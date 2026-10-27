<?php

declare(strict_types=1);

namespace Platform\Core;

/**
 * The incoming request, normalised.
 *
 * The path is resolved relative to the directory the front controller is served
 * from, so the platform works at a document root, in a subfolder like
 * /RAIX/public, or behind a vhost, with no configuration.
 */
final class Request
{
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        public readonly array $server,
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        // Browsers can only send GET and POST; honour the override a form posts.
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string) $_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }

        return new self(
            method: $method,
            path:   self::resolvePath(),
            query:  $_GET,
            body:   self::resolveBody(),
            server: $_SERVER,
        );
    }

    /**
     * Path with the application's base directory stripped, always leading-slashed.
     *
     * Uses base_url_path() rather than deriving its own base, so routing and
     * link generation cannot disagree about where the application starts —
     * which they did when the site was reached without /public and only one of
     * them knew about the rewrite.
     */
    private static function resolvePath(): string
    {
        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $base = base_url_path();

        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        $path = '/' . trim(rawurldecode($path), '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /** Form fields, or a decoded JSON body when that is what was sent. */
    private static function resolveBody(): array
    {
        $type = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');

        if (str_contains(strtolower($type), 'application/json')) {
            $raw = file_get_contents('php://input');
            if ($raw === false || $raw === '') {
                return [];
            }
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return $_POST;
    }

    /**
     * A copy of this request pointed at a different path.
     *
     * Used by the front controller to retry a root-relative URL under the
     * default app's prefix, so '/assess' and '/raix-director-scorecard/assess' both
     * resolve without the app knowing its own slug.
     */
    public function withPath(string $path): self
    {
        $path = '/' . trim($path, '/');

        return new self(
            method: $this->method,
            path:   $path === '/' ? '/' : rtrim($path, '/'),
            query:  $this->query,
            body:   $this->body,
            server: $this->server,
        );
    }

    /** First path segment — the app slug, when one is addressed. */
    public function firstSegment(): string
    {
        return explode('/', trim($this->path, '/'))[0] ?? '';
    }

    /** @return list<string> */
    public function segments(): array
    {
        return array_values(array_filter(explode('/', trim($this->path, '/')), static fn ($s) => $s !== ''));
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function expectsJson(): bool
    {
        $accept = strtolower((string) ($this->server['HTTP_ACCEPT'] ?? ''));

        return str_contains($accept, 'application/json')
            || str_starts_with($this->path, '/api/')
            || str_contains($this->path, '/api/');
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    /** Hashed client address — used for rate limiting, never stored raw. */
    public function ipHash(): string
    {
        $ip = (string) ($this->server['REMOTE_ADDR'] ?? '');

        return hash('sha256', $ip . '|' . config('app.key'));
    }
}
