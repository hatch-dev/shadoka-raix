<?php

declare(strict_types=1);

namespace Platform\Core;

use RuntimeException;

/**
 * Method + path to a controller action.
 *
 * Each app's routes are loaded under its own slug prefix, so an app declares
 * '/results/{token}' and the platform serves it at '/raix-director-scorecard/results/{token}'.
 * An app therefore never needs to know its own public URL, which is what makes
 * the slug (and later, a subdomain) a configuration decision.
 */
final class Router
{
    /** @var array<string, list<array{regex:string, params:list<string>, action:mixed, name:?string, app:?string}>> */
    private array $routes = [];

    private string $prefix = '';

    private ?string $currentApp = null;

    /** @var array<string, string> */
    private array $named = [];

    public function get(string $path, mixed $action, ?string $name = null): self
    {
        return $this->add('GET', $path, $action, $name);
    }

    public function post(string $path, mixed $action, ?string $name = null): self
    {
        return $this->add('POST', $path, $action, $name);
    }

    public function put(string $path, mixed $action, ?string $name = null): self
    {
        return $this->add('PUT', $path, $action, $name);
    }

    public function patch(string $path, mixed $action, ?string $name = null): self
    {
        return $this->add('PATCH', $path, $action, $name);
    }

    public function delete(string $path, mixed $action, ?string $name = null): self
    {
        return $this->add('DELETE', $path, $action, $name);
    }

    public function add(string $method, string $path, mixed $action, ?string $name = null): self
    {
        $full = $this->normalise($this->prefix . '/' . ltrim($path, '/'));

        // '/results/{token}' -> '#^/results/(?P<token>[^/]+)$#'
        $params = [];
        $regex  = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];
                return '(?P<' . $m[1] . '>[^/]+)';
            },
            $full
        );

        $this->routes[strtoupper($method)][] = [
            'regex'  => '#^' . $regex . '$#',
            'params' => $params,
            'action' => $action,
            'name'   => $name,
            'app'    => $this->currentApp,
        ];

        if ($name !== null) {
            $this->named[$name] = $full;
        }

        return $this;
    }

    /** Register a group of routes under a path prefix. */
    public function group(string $prefix, callable $callback, ?string $app = null): void
    {
        $previousPrefix = $this->prefix;
        $previousApp    = $this->currentApp;

        $this->prefix     = $this->normalise($this->prefix . '/' . trim($prefix, '/'));
        $this->currentApp = $app ?? $this->currentApp;

        $callback($this);

        $this->prefix     = $previousPrefix;
        $this->currentApp = $previousApp;
    }

    /**
     * Load every enabled app's route files, each under its own slug prefix.
     *
     * Route files receive $router and $app, and are the only place an app maps
     * a URL to a controller.
     */
    public function loadApps(): void
    {
        foreach (AppRegistry::all() as $app) {
            foreach ($app->routes as $file) {
                $path = $app->path('routes/' . $file);

                if (!is_file($path)) {
                    continue;
                }

                // admin.php mounts under /admin/{slug}; everything else under /{slug}.
                $prefix = $file === 'admin.php' ? 'admin/' . $app->slug : $app->slug;

                $this->group($prefix, function (Router $router) use ($path, $app): void {
                    $load = static function (string $__path, Router $router, AppManifest $app): void {
                        require $__path;
                    };
                    $load($path, $router, $app);
                }, $app->slug);
            }
        }
    }

    /** Resolve a request to a Response, or null when nothing matched. */
    public function dispatch(Request $request): ?Response
    {
        foreach ($this->routes[$request->method] ?? [] as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }

            $arguments = [];
            foreach ($route['params'] as $param) {
                $arguments[$param] = $matches[$param];
            }

            return $this->call($route['action'], $request, $arguments, $route['app']);
        }

        return null;
    }

    private function call(mixed $action, Request $request, array $arguments, ?string $app): Response
    {
        if (is_callable($action)) {
            $result = $action($request, $arguments);
        } elseif (is_array($action) && count($action) === 2) {
            [$class, $method] = $action;

            if (!class_exists($class)) {
                throw new RuntimeException("Controller {$class} does not exist.");
            }

            $controller = new $class($request, $app === null ? null : AppRegistry::get($app));

            if (!method_exists($controller, $method)) {
                throw new RuntimeException("Controller {$class} has no method {$method}().");
            }

            $result = $controller->{$method}(...array_values($arguments));
        } else {
            throw new RuntimeException('Route action must be a callable or [Controller::class, method].');
        }

        return $result instanceof Response ? $result : Response::make((string) $result);
    }

    /** URL for a named route, with {placeholders} filled in. */
    public function route(string $name, array $parameters = []): string
    {
        if (!isset($this->named[$name])) {
            throw new RuntimeException("No route named '{$name}'.");
        }

        $path = $this->named[$name];
        foreach ($parameters as $key => $value) {
            $path = str_replace('{' . $key . '}', rawurlencode((string) $value), $path);
        }

        return url(ltrim($path, '/'));
    }

    /** @return array<string, string> name => path, for debugging and the admin nav */
    public function names(): array
    {
        return $this->named;
    }

    public function count(): int
    {
        return array_sum(array_map('count', $this->routes));
    }

    private function normalise(string $path): string
    {
        $path = '/' . trim(preg_replace('#/+#', '/', $path) ?? '', '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
