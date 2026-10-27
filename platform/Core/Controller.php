<?php

declare(strict_types=1);

namespace Platform\Core;

/**
 * Base controller.
 *
 * Every controller knows which app it belongs to (or null for platform-level
 * screens), which is how a controller reaches its own prefixed tables and
 * published assets without hard-coding either.
 */
abstract class Controller
{
    public function __construct(
        protected readonly Request $request,
        protected readonly ?AppManifest $app = null,
    ) {
    }

    /** This app's prefixed table name. */
    protected function table(string $name): string
    {
        return $this->app?->table($name) ?? $name;
    }

    /**
     * Read the owning app's config, falling back to the platform's.
     *
     * Controllers must use this rather than the bare config() helper, which
     * only ever sees platform config and would silently return the default for
     * anything an app declared.
     */
    protected function appConfig(string $key, mixed $default = null): mixed
    {
        if ($this->app === null) {
            return config($key, $default);
        }

        $sentinel = "\0missing\0";
        $value    = $this->app->config($key, $sentinel);

        return $value === $sentinel ? config($key, $default) : $value;
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    /** Standard success envelope for API endpoints. */
    protected function ok(mixed $data = null, array $extra = []): Response
    {
        return Response::json(['success' => true, 'data' => $data] + $extra);
    }

    /** Standard failure envelope. Never leaks an exception message. */
    protected function fail(string $message, int $status = 400, array $errors = []): Response
    {
        $payload = ['success' => false, 'message' => $message];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return Response::json($payload, $status);
    }

    protected function redirect(string $path): Response
    {
        return Response::redirect(url($path));
    }
}
