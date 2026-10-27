<?php

declare(strict_types=1);

namespace Apps\RaixDirectorScorecardPublic\Services;

use Platform\Core\AppManifest;
use Platform\Core\Settings;

/**
 * C5 of directive v13: a dated copy of every HTML page deployed, labelled with
 * the NOTICE_VERSION it contains.
 *
 * Each consent record names the notice version the person saw
 * (org.consent.notice), so these copies are how anyone later shows exactly
 * what a given person agreed to. The copy is of the page AS SERVED — line 922
 * included — not of the supplied file, because the served page is what was
 * deployed.
 *
 * Runs on every page view but costs one settings read: a copy is written only
 * the first time a page with a new md5 is served. Nothing here ever deletes or
 * overwrites a copy.
 *
 * storage/archive/notice-versions/
 *   {date}_{NOTICE}_deployed_{md5 first 8}.html   one per deployed page
 *   index.json                                    what each file is, and when it was first served
 */
final class NoticeArchive
{
    public function __construct(private readonly AppManifest $app)
    {
    }

    public static function dir(): string
    {
        return storage_path('archive/notice-versions');
    }

    private function settingKey(): string
    {
        return 'app.' . $this->app->slug . '.deployed_page_md5';
    }

    /** The NOTICE_VERSION a page declares, read from the page itself. */
    public static function noticeVersion(string $html): ?string
    {
        return preg_match("/const NOTICE_VERSION='([A-Za-z0-9._-]+)';/", $html, $m) ? $m[1] : null;
    }

    /**
     * Archive $html if this exact page has not been archived before.
     *
     * @return string|null the archived file's name when one was written
     */
    public function record(string $html, string $apiBase): ?string
    {
        $md5 = md5($html);

        if (Settings::get($this->settingKey()) === $md5) {
            return null;
        }

        $index = self::index();

        foreach ($index as $entry) {
            if (($entry['md5'] ?? null) === $md5) {
                Settings::put($this->settingKey(), $md5);

                return null;
            }
        }

        $notice = self::noticeVersion($html) ?? 'no-notice-version';
        $name   = gmdate('Y-m-d') . '_' . $notice . '_deployed_' . substr($md5, 0, 8) . '.html';
        $dir    = self::dir();

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // x: never replace a file that is already there.
        $handle = @fopen($dir . '/' . $name, 'xb');

        if ($handle !== false) {
            fwrite($handle, $html);
            fclose($handle);
        }

        $index[] = [
            'file'           => $name,
            'kind'           => 'deployed page',
            'notice_version' => $notice,
            'md5'            => $md5,
            'bytes'          => strlen($html),
            'first_served'   => gmdate('Y-m-d\TH:i:s\Z'),
            'api_base'       => $apiBase,
            'source'         => ScorecardPage::FILE . ' (md5 ' . ScorecardPage::MD5 . ')',
        ];

        self::writeIndex($index);
        Settings::put($this->settingKey(), $md5);

        return $name;
    }

    /** @return list<array<string, mixed>> */
    public static function index(): array
    {
        $file = self::dir() . '/index.json';

        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) && isset($data['copies']) && is_array($data['copies']) ? array_values($data['copies']) : [];
    }

    /** @param list<array<string, mixed>> $copies */
    public static function writeIndex(array $copies): void
    {
        file_put_contents(
            self::dir() . '/index.json',
            json_encode([
                'about'  => 'Directive v13 C5: a dated copy of every deployed scorecard page, labelled with the NOTICE_VERSION it contains. '
                          . 'A consent record\'s org.consent.notice names the version the person saw. Never delete or edit these files.',
                'copies' => $copies,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            LOCK_EX
        );
    }
}
