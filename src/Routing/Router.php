<?php

declare(strict_types=1);

namespace PHPAML\Routing;

use PHPAML\Container;
use PHPAML\Http\Request;
use PHPAML\Http\Response;
use PHPAML\Middleware\MiddlewareInterface;
use PHPAML\Middleware\MiddlewarePipeline;
use RuntimeException;

final class Router
{
    /** @var list<array{method: string, path: string, pattern: string, handler: array{0: class-string, 1: string}, middleware: list<class-string>, name: ?string}> */
    private array $routes = [];

    public function __construct(private Container $container)
    {
    }

    /** @param array{0: class-string, 1: string} $handler @param list<class-string> $middleware */
    public function add(string $method, string $path, array $handler, array $middleware = [], ?string $name = null): void
    {
        $path = '/' . trim($path, '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static fn (array $match): string => '(?P<' . $match[1] . '>[^/]+)',
            $path
        );
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'pattern' => '#^' . $pattern . '$#',
            'handler' => $handler,
            'middleware' => $middleware,
            'name' => $name,
        ];
    }

    /** @param array{0: class-string, 1: string} $handler */
    public function get(string $path, array $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('GET', $path, $handler, $middleware, $name);
    }

    /** @param array{0: class-string, 1: string} $handler */
    public function post(string $path, array $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('POST', $path, $handler, $middleware, $name);
    }

    /**
     * @param array<string, array{0: class-string, 1: string}|array<string, mixed>> $routes
     * @param list<class-string> $middleware
     */
    public function group(string $prefix, array $routes, array $middleware = []): void
    {
        foreach ($routes as $definition => $routeConfig) {
            [$method, $path] = array_pad(explode(' ', $definition, 2), 2, '');
            $handler = isset($routeConfig['handler']) ? $routeConfig['handler'] : $routeConfig;
            $this->add(
                $method,
                '/' . trim($prefix, '/') . '/' . ltrim($path, '/'),
                $handler,
                array_merge($middleware, $routeConfig['middleware'] ?? []),
                $routeConfig['name'] ?? null
            );
        }
    }

    /** @param array<string, array{0: class-string, 1: string}|array{handler: array{0: class-string, 1: string}, middleware?: list<class-string>, name?: string}> $routes */
    public function addRoutes(array $routes): void
    {
        foreach ($routes as $definition => $routeConfig) {
            [$method, $path] = array_pad(explode(' ', $definition, 2), 2, '');
            if ($method === '' || $path === '') {
                throw new RuntimeException("Route invalide : '{$definition}'.");
            }
            $handler = isset($routeConfig['handler']) ? $routeConfig['handler'] : $routeConfig;
            $this->add(
                $method,
                $path,
                $handler,
                $routeConfig['middleware'] ?? [],
                $routeConfig['name'] ?? null
            );
        }
    }

    public function dispatch(Request $request): Response
    {
        $allowedMethods = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['pattern'], $request->path(), $matches)) {
                continue;
            }
            if ($route['method'] !== $request->method()) {
                $allowedMethods[] = $route['method'];
                continue;
            }
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $request = $request->withAttribute($key, urldecode($value));
                }
            }
            $destination = function (Request $request) use ($route): Response {
                [$controllerClass, $action] = $route['handler'];
                $controller = $this->container->get($controllerClass);
                if (!method_exists($controller, $action)) {
                    throw new RuntimeException("L'action '{$action}' est introuvable sur '{$controllerClass}'.");
                }
                $response = $controller->{$action}($request);
                if (!$response instanceof Response) {
                    throw new RuntimeException("L'action '{$controllerClass}::{$action}' doit retourner une Response.");
                }
                return $response;
            };
            $middlewares = array_map(function (string $class): MiddlewareInterface {
                $middleware = $this->container->get($class);
                if (!$middleware instanceof MiddlewareInterface) {
                    throw new RuntimeException("'{$class}' n'est pas un middleware.");
                }
                return $middleware;
            }, $route['middleware']);
            return (new MiddlewarePipeline($middlewares))->handle($request, $destination);
        }
        if ($allowedMethods !== []) {
            return Response::html('<h1>405</h1><p>Méthode non autorisée.</p>', 405)
                ->withHeader('Allow', implode(', ', array_unique($allowedMethods)));
        }
        return Response::html('<h1>404</h1><p>Page introuvable.</p>', 404);
    }

    /** @return list<array<string, mixed>> */
    public function routes(): array
    {
        return $this->routes;
    }

    /** @param array<string, string|int> $parameters */
    public function url(string $name, array $parameters = []): string
    {
        foreach ($this->routes as $route) {
            if ($route['name'] !== $name) {
                continue;
            }
            return preg_replace_callback('/\{([^}]+)\}/', static function (array $match) use ($parameters): string {
                if (!array_key_exists($match[1], $parameters)) {
                    throw new RuntimeException("Paramètre de route manquant : '{$match[1]}'.");
                }
                return rawurlencode((string) $parameters[$match[1]]);
            }, $route['path']);
        }
        throw new RuntimeException("Route nommée introuvable : '{$name}'.");
    }
}
