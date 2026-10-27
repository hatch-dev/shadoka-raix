<?php

declare(strict_types=1);

namespace Platform\Core;

use RuntimeException;
use Throwable;

/**
 * Sends a supplied single-file page in pieces a browser can cache.
 *
 * The scorecard pages arrive as one 1.3MB HTML file: base64 fonts and a hero
 * image inside the <head> stylesheet, and Chart.js and jsPDF inline before
 * </body>. Nothing can paint until the whole stylesheet has arrived, nothing
 * is cached between visits or between the five scorecards (they carry the
 * same fonts, image and libraries), and a phone downloads a 1920px image.
 *
 * Done as the page is sent, after ScorecardPage::html() — the supplied file,
 * the C5 archive copy and the "differs only on patched lines" check all still
 * see the whole self-contained page. What changes is transport only:
 *
 *  - base64 fonts and images in the stylesheets become files under
 *    public/assets/page/, named by their content (so one copy serves every
 *    app, and a changed file can never be served stale);
 *  - a large background image gets a phone-sized copy for narrow screens, and
 *    both are preloaded, because it is the largest thing painted;
 *  - Chart.js loads with `defer` (no page draws a chart while loading);
 *  - jsPDF loads once the page has finished loading (every use goes through
 *    the page's pdfDoc(), which reports a missing library rather than failing).
 *
 * Anything unexpected — a folder that cannot be written, a library not found —
 * and the page is sent whole, exactly as before. The result is cached per
 * input page in storage/cache/page-assets/.
 */
final class PageAssets
{
    /** Under public/assets/. */
    private const DIR = 'page';

    /** A data: URI shorter than this stays inline: not worth a request. */
    private const MIN_EXTRACT = 4096;

    /** Narrow screens get a copy of a wide background image at this width. */
    private const PHONE_WIDTH   = 900;
    private const PHONE_QUALITY = 70;

    /**
     * Inline libraries, recognised by the comment the supplied pages open
     * them with: marker => how the extracted file is loaded.
     */
    private const LIBRARIES = [
        '/* Chart.js ' => 'defer',
        '/* jsPDF '    => 'after-load',
    ];

    private const HTACCESS = <<<'HTACCESS'
        # Written by Platform\Core\PageAssets. Every file here is named by its
        # content, so it can be kept for a year: a change is a new name.
        <IfModule mod_headers.c>
            <FilesMatch "\.(js|woff2?|webp|png|jpe?g|avif)$">
                Header set Cache-Control "public, max-age=31536000, immutable"
            </FilesMatch>
        </IfModule>

        HTACCESS;

    /** $html with its heavy parts moved to cacheable files, or $html itself if that cannot be done. */
    public static function split(string $html): string
    {
        $cache = storage_path('cache/page-assets/' . md5($html) . '.html');

        if (is_file($cache)) {
            $out = (string) file_get_contents($cache);

            if ($out !== '' && self::filesPresent($out)) {
                return $out;
            }
        }

        try {
            $out = self::build($html);
        } catch (Throwable $e) {
            logger('PageAssets: page sent whole: ' . $e->getMessage(), 'warning');

            return $html;
        }

        self::writeAtomic($cache, $out);

        return $out;
    }

    /**
     * The same split for a static page that Apache serves itself (RAIX_Index.html),
     * done once by scripts/split-static-page.php rather than per request. Such a
     * page has no inline libraries, so none are required; any failure throws.
     */
    public static function splitStatic(string $html): string
    {
        return self::build($html, false);
    }

    private static function build(string $html, bool $libraries = true): string
    {
        $images      = [];
        $phoneCopies = [];

        // 1. Libraries first, so nothing below looks inside a script.
        $afterLoad = [];
        $found     = [];
        $html = (string) preg_replace_callback('#<script>(.*?)</script>#s', static function (array $m) use (&$afterLoad, &$found): string {
            foreach (self::LIBRARIES as $marker => $mode) {
                if (str_starts_with(ltrim(substr($m[1], 0, 200)), $marker)) {
                    $found[$marker] = true;
                    $url = self::publish($m[1], 'js');

                    if ($mode === 'defer') {
                        return '<script src="' . $url . '" defer></script>';
                    }
                    $afterLoad[] = $url;

                    return '';
                }
            }

            return $m[0];
        }, $html);

        if ($libraries && count($found) !== count(self::LIBRARIES)) {
            throw new RuntimeException('an inline library was not found');
        }

        // 2. data: URIs, in stylesheets only (a script may need its base64 as data).
        $html = (string) preg_replace_callback('#<style>(.*?)</style>#s', static function (array $m) use (&$images, &$phoneCopies): string {
            $css = (string) preg_replace_callback(
                '#data:(font/woff2|font/woff|image/webp|image/png|image/jpeg|image/avif);base64,([A-Za-z0-9+/=]+)#',
                static function (array $d) use (&$images): string {
                    if (strlen($d[2]) < self::MIN_EXTRACT) {
                        return $d[0];
                    }
                    $bytes = base64_decode($d[2], true);
                    if ($bytes === false) {
                        throw new RuntimeException('a data: URI is not valid base64');
                    }
                    $ext = ['font/woff2' => 'woff2', 'font/woff' => 'woff', 'image/webp' => 'webp',
                            'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/avif' => 'avif'][$d[1]];
                    $url = self::publish($bytes, $ext);

                    if (str_starts_with($d[1], 'image/')) {
                        $images[$url] = $bytes;
                    }

                    return $url;
                },
                $m[1]
            );

            return '<style>' . self::phoneBackgrounds($css, $images, $phoneCopies) . '</style>';
        }, $html);

        // 3. Preload the hero (the first image given a phone copy), and start jsPDF after load.
        $preload = '';
        foreach ($phoneCopies as $full => $phone) {
            $preload = '<link rel="preload" as="image" href="' . $phone . '" media="(max-width:' . self::PHONE_WIDTH . 'px)">'
                     . '<link rel="preload" as="image" href="' . $full . '" media="(min-width:' . (self::PHONE_WIDTH + 1) . 'px)">';
            break;
        }

        if ($preload !== '') {
            $at = strpos($html, '<style>');
            $html = $at === false ? $html : substr($html, 0, $at) . $preload . substr($html, $at);
        }

        if ($afterLoad !== []) {
            $load = "<script>addEventListener('load',function(){"
                  . implode('', array_map(static fn (string $u): string => "var s=document.createElement('script');s.src='" . $u . "';document.head.appendChild(s);", $afterLoad))
                  . '});</script>';
            $at   = strrpos($html, '</body>');
            if ($at === false) {
                throw new RuntimeException('no </body>');
            }
            $html = substr($html, 0, $at) . $load . substr($html, $at);
        }

        return $html;
    }

    /**
     * After each CSS rule whose background is a wide extracted image, a rule
     * giving narrow screens a phone-sized copy instead.
     *
     * @param array<string, string> $images      URL => bytes
     * @param array<string, string> $phoneCopies full image URL => phone copy URL, added to
     */
    private static function phoneBackgrounds(string $css, array $images, array &$phoneCopies): string
    {
        foreach ($images as $url => $bytes) {
            if (isset($phoneCopies[$url]) || !str_contains($css, $url)) {
                continue;
            }
            $phone = self::phoneCopy($bytes);
            if ($phone === null) {
                continue;
            }

            $quoted = preg_quote($url, '#');
            $css = (string) preg_replace_callback(
                '#([^{}]+)\{([^{}]*background(?:-image)?:[^{};]*url\(["\']?' . $quoted . '["\']?\)[^{}]*)\}#',
                static fn (array $r): string => $r[0] . '@media (max-width:' . self::PHONE_WIDTH . 'px){'
                    . trim($r[1]) . '{background-image:url("' . $phone . '")}}',
                $css,
                -1,
                $count
            );

            if ($count > 0) {
                $phoneCopies[$url] = $phone;
            }
        }

        return $css;
    }

    /** URL of a PHONE_WIDTH-wide WebP copy of $bytes, or null when it is narrow already or GD cannot do it. */
    private static function phoneCopy(string $bytes): ?string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
            return null;
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false || imagesx($image) <= self::PHONE_WIDTH * 1.3) {
            return null;
        }
        $small = imagescale($image, self::PHONE_WIDTH);
        if ($small === false) {
            return null;
        }
        ob_start();
        $ok = imagewebp($small, null, self::PHONE_QUALITY);
        $webp = (string) ob_get_clean();

        return $ok && $webp !== '' ? self::publish($webp, 'webp') : null;
    }

    /** Write $bytes under public/assets/page/ by content name (once) and return its URL. */
    private static function publish(string $bytes, string $ext): string
    {
        $dir = base_path('public/assets/' . self::DIR);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('cannot create ' . $dir);
        }
        if (!is_file($dir . '/.htaccess')) {
            self::writeAtomic($dir . '/.htaccess', self::HTACCESS);
        }

        $name = substr(md5($bytes), 0, 16) . '.' . $ext;

        if (!is_file($dir . '/' . $name) && !self::writeAtomic($dir . '/' . $name, $bytes)) {
            throw new RuntimeException('cannot write ' . $name);
        }

        // Not asset(): the name already changes with the content, so no ?v= and no stat.
        return mount_base_path() . '/assets/' . self::DIR . '/' . $name;
    }

    /** Every page file a cached result names still exists (someone may have cleared the folder). */
    private static function filesPresent(string $html): bool
    {
        preg_match_all('#/assets/' . self::DIR . '/([a-f0-9]{16}\.[a-z0-9]+)#', $html, $m);

        foreach (array_unique($m[1]) as $name) {
            if (!is_file(base_path('public/assets/' . self::DIR . '/' . $name))) {
                return false;
            }
        }

        return true;
    }

    private static function writeAtomic(string $path, string $bytes): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($tmp, $bytes) === false) {
            return false;
        }
        @chmod($tmp, 0664);

        return @rename($tmp, $path) || (@unlink($tmp) && false);
    }
}
