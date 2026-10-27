<?php

declare(strict_types=1);

namespace Platform\Core;

use RuntimeException;
use Throwable;

/**
 * Plain PHP templates with layouts and partials.
 *
 * No template engine: PHP already is one, and a XAMPP deployment should not
 * need a compile step or a writable cache directory to render a page.
 *
 * The one rule this enforces is escaping. Templates receive data, and `e()` is
 * the only way it reaches the page. The legacy build's client-side `esc()`
 * escaped `&`, `<` and `>` but not quotes, so a board name containing a double
 * quote broke out of its attribute — `e()` uses ENT_QUOTES and does not.
 */
final class View
{
    /** @var list<string> directories searched in order */
    private array $paths = [];

    /** @var array<string, mixed> data shared with every template */
    private array $shared = [];

    private ?string $layout = null;

    /** @var array<string, string> captured sections */
    private array $sections = [];

    /** @var list<string> open section stack */
    private array $openSections = [];

    public function __construct(?AppManifest $app = null)
    {
        // An app's own views win; the platform's are the fallback, so an app
        // can override a shared partial by simply having one of its own.
        if ($app !== null) {
            $this->paths[] = $app->path('Views');
        }

        $this->paths[] = base_path('platform/Admin/Views');
        $this->paths[] = base_path('platform/Views');

        $this->shared = [
            'appName'   => (string) config('app.name'),
            'copyright' => (string) config('app.copyright'),
            'owner'     => (string) config('app.owner'),
        ];
    }

    public function share(string $key, mixed $value): self
    {
        $this->shared[$key] = $value;

        return $this;
    }

    /** Render a template to a Response. */
    public function response(string $template, array $data = [], int $status = 200): Response
    {
        return Response::make($this->render($template, $data), $status);
    }

    /**
     * Render a template.
     *
     * A template may call $this->extend('layouts/public') to wrap itself; the
     * layout then echoes $this->section('content').
     */
    public function render(string $template, array $data = []): string
    {
        $file = $this->resolve($template);

        $this->layout = null;

        $content = $this->capture($file, array_merge($this->shared, $data));

        if ($this->layout === null) {
            return $content;
        }

        $layout        = $this->layout;
        $this->layout  = null;
        $this->sections['content'] = $this->sections['content'] ?? $content;

        return $this->capture(
            $this->resolve($layout),
            array_merge($this->shared, $data)
        );
    }

    /** Render a partial inline, inheriting nothing. */
    public function partial(string $template, array $data = []): string
    {
        return $this->capture($this->resolve($template), array_merge($this->shared, $data));
    }

    /* ------------------------------------------------------------------ */
    /* Called from inside templates                                        */
    /* ------------------------------------------------------------------ */

    public function extend(string $layout): void
    {
        $this->layout = $layout;
    }

    public function start(string $section): void
    {
        $this->openSections[] = $section;
        ob_start();
    }

    public function stop(): void
    {
        $section = array_pop($this->openSections);

        if ($section === null) {
            throw new RuntimeException('View::stop() called with no open section.');
        }

        $this->sections[$section] = (string) ob_get_clean();
    }

    public function section(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    public function hasSection(string $name): bool
    {
        return isset($this->sections[$name]);
    }

    /* ------------------------------------------------------------------ */

    private function capture(string $file, array $data): string
    {
        $level = ob_get_level();

        // $this is bound, so templates can call $this->extend()/start()/stop().
        $render = function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            include $__file;

            return (string) ob_get_clean();
        };

        try {
            return $render->call($this, $file, $data);
        } catch (Throwable $e) {
            // A template that throws mid-output would otherwise leave buffers
            // open and corrupt the response.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
    }

    private function resolve(string $template): string
    {
        $relative = str_replace('.', '/', trim($template, '/')) . '.php';

        foreach ($this->paths as $path) {
            $file = $path . '/' . $relative;

            if (is_file($file)) {
                return $file;
            }
        }

        throw new RuntimeException(sprintf(
            "View '%s' not found. Looked in: %s",
            $template,
            implode(', ', $this->paths)
        ));
    }
}
