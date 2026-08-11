<?php

declare(strict_types=1);

namespace PHPAML;

use PHPAML\Http\Request;
use PHPAML\Http\Response;
use PHPAML\Middleware\ErrorHandlerMiddleware;
use PHPAML\Middleware\MiddlewareInterface;
use PHPAML\Middleware\MiddlewarePipeline;
use PHPAML\Middleware\CsrfMiddleware;
use PHPAML\Mvc\View;
use PHPAML\Routing\Router;
use PHPAML\Session\Session;
use PHPAML\Data\Connection;

final class WebApplication
{
    private Container $container;
    private Router $router;
    private MiddlewarePipeline $pipeline;

    /** @param array<string, mixed> $config */
    public function __construct(private array $config)
    {
        $this->container = new Container();
        $this->container->set(Container::class, $this->container);
        $session = new Session($config['session'] ?? []);
        $this->container->set(Session::class, $session);
        $this->container->set(View::class, new View((string) $config['views_path'], $session));
        if (!empty($config['database']['dsn'])) {
            $this->container->set(Connection::class, new Connection(
                (string) $config['database']['dsn'],
                $config['database']['username'] ?: null,
                $config['database']['password'] ?: null
            ));
        }

        $this->router = new Router($this->container);
        $this->router->addRoutes($config['routes'] ?? []);

        $middlewares = [
            new ErrorHandlerMiddleware((bool) ($config['debug'] ?? false)),
            new CsrfMiddleware($session),
        ];
        foreach ($config['middlewares'] ?? [] as $middlewareClass) {
            $middleware = $this->container->get($middlewareClass);
            if ($middleware instanceof MiddlewareInterface) {
                $middlewares[] = $middleware;
            }
        }
        $this->pipeline = new MiddlewarePipeline($middlewares);
    }

    public function handle(Request $request): Response
    {
        return $this->pipeline->handle(
            $request,
            fn (Request $request): Response => $this->router->dispatch($request)
        );
    }

    public function run(): void
    {
        $this->handle(Request::capture())->send();
    }

    public function container(): Container
    {
        return $this->container;
    }
}
