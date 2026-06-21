<?php

declare(strict_types=1);

namespace App\Helpers;

class Router
{
    private array $routes = [];
    private string $prefix = '';

    /**
     * Strip this prefix from every incoming URI before matching.
     * Set to '/v1' when the API base URL includes /v1.
     */
    public function setPrefix(string $prefix): void
    {
        $this->prefix = rtrim($prefix, '/');
    }

    public function get(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->routes[] = ['GET', $path, $handler, $middleware];
    }

    public function post(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->routes[] = ['POST', $path, $handler, $middleware];
    }

    public function put(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->routes[] = ['PUT', $path, $handler, $middleware];
    }

    public function delete(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->routes[] = ['DELETE', $path, $handler, $middleware];
    }

    public function patch(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->routes[] = ['PATCH', $path, $handler, $middleware];
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Strip SCRIPT_NAME prefix for PATH_INFO-style URLs
        // e.g. /index.php/v1/coach/clients → /v1/coach/clients
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        if ($scriptName !== '' && $scriptName !== '/' && str_starts_with($uri, $scriptName)) {
            $uri = substr($uri, strlen($scriptName));
        } else {
            // Strip the directory portion of SCRIPT_NAME for subdirectory installs
            // e.g. /subdir/index.php → strip /subdir from /subdir/v1/path
            $baseDir = dirname($scriptName);
            if ($baseDir !== '/' && $baseDir !== '\\' && $baseDir !== '.' && str_starts_with($uri, $baseDir)) {
                $uri = substr($uri, strlen($baseDir));
            }
        }

        // Normalise: collapse consecutive slashes, ensure leading /, strip trailing /
        $uri = '/' . ltrim($uri, '/');
        $uri = (string) preg_replace('#/+#', '/', $uri);
        $uri = rtrim($uri, '/') ?: '/';

        // Strip base prefix (e.g. /v1) only when followed by / or end-of-string
        if ($this->prefix !== '') {
            $prefixLen = strlen($this->prefix);
            if (
                str_starts_with($uri, $this->prefix)
                && (strlen($uri) === $prefixLen || $uri[$prefixLen] === '/')
            ) {
                $uri = substr($uri, $prefixLen);
                $uri = $uri === '' || $uri === false ? '/' : $uri;
            }
        }

        foreach ($this->routes as [$routeMethod, $routePath, $handler, $middleware]) {
            if ($method !== $routeMethod) continue;

            $pattern = preg_replace('#/:([^/]+)#', '/(?P<$1>[^/]+)', $routePath);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                // Run middleware chain
                foreach ($middleware as $mw) {
                    $mwInstance = new $mw();
                    $mwInstance->handle($params);
                }

                // Call controller
                if (is_array($handler)) {
                    [$class, $method_name] = $handler;
                    $controller = new $class();
                    $controller->$method_name($params);
                } else {
                    $handler($params);
                }
                return;
            }
        }

        Response::json(['error' => 'Route not found'], 404);
    }
}
