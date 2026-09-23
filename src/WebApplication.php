<?php

declare(strict_types=1);

namespace PHPAML;

use Closure;
use PHPAML\Http\Request;
use PHPAML\Http\Response;
use PHPAML\Middleware\ErrorHandlerMiddleware;
use PHPAML\Middleware\MiddlewareInterface;
use PHPAML\Middleware\MiddlewarePipeline;
use PHPAML\Middleware\CsrfMiddleware;
use PHPAML\Mvc\View;
use PHPAML\Routing\Router;
use PHPAML\Routing\Route;
use PHPAML\Session\Session;
use PHPAML\Data\Connection;
use PHPAML\Logging\Logger;
use PHPAML\Middleware\RateLimitMiddleware;
use PHPAML\Middleware\SecurityHeadersMiddleware;
use PHPAML\Api\TokenManager;
use PHPAML\Api\AuthManager;
use PHPAML\Middleware\ApiMiddleware;
use PHPAML\Middleware\RequestIdMiddleware;

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
        $sessionConfig = is_array($config['session'] ?? null) ? $config['session'] : [];
        $this->container->scoped(Session::class, static function (Container $container) use ($sessionConfig): Session {
            $request = $container->get(Request::class);
            $resolvedConfig = $sessionConfig;
            if (!array_key_exists('secure', $resolvedConfig) && $request instanceof Request) {
                $resolvedConfig['secure'] = $request->isSecure();
            }
            return new Session($resolvedConfig);
        });
        $bootstrappers = is_array($config['bootstrappers'] ?? null) ? $config['bootstrappers'] : [];
        foreach ($bootstrappers as $bootstrapper) {
            if (!is_callable($bootstrapper)) {
                continue;
            }
            $bootstrapper($this->container, $config);
        }
        $api = is_array($config['api'] ?? null) ? $config['api'] : [];
        if (($api['enabled'] ?? false) === true) {
            $tokenConfig = is_array($api['tokens'] ?? null) ? $api['tokens'] : [];
            $this->container->set(TokenManager::class, new TokenManager(
                (string) ($tokenConfig['storage_path'] ?? sys_get_temp_dir() . '/phpaml-api-tokens.json'),
                (int) ($tokenConfig['ttl'] ?? 86400)
            ));
        }
        $dataConfig = is_array($config['data'] ?? null) ? $config['data'] : null;
        // Compatibility bridge for projects generated before application-owned
        // bootstrappers. New projects register Data in public/index.php.
        if (($config['legacy_data_bootstrap'] ?? true) === true
            && $dataConfig !== null
            && class_exists(\AML\Data\Connections\ConnectionManager::class)) {
            $projectRoot = (string) ($config['project_root'] ?? dirname((string) ($dataConfig['migrations_path'] ?? __DIR__), 2));
            $manager = new \AML\Data\Connections\ConnectionManager($projectRoot, $dataConfig);
            $this->container->set(\AML\Data\Connections\ConnectionManager::class, $manager);
            $this->container->set(\AML\Data\Connection::class, $manager->sql());
        }
        if (isset($config['views_path']) && $config['views_path'] !== '') {
            $viewsPath = (string) $config['views_path'];
            $this->container->scoped(
                View::class,
                static fn (Container $container): View => new View($viewsPath, $container->get(Session::class))
            );
        }
        if (!empty($config['database']['dsn'])) {
            $legacyConnection = new Connection(
                (string) $config['database']['dsn'],
                $config['database']['username'] ?: null,
                $config['database']['password'] ?: null
            );
            $this->container->set(Connection::class, $legacyConnection);
            $authConfig = is_array($api['auth'] ?? null) ? $api['auth'] : [];
            if (($api['enabled'] ?? false) === true && ($authConfig['enabled'] ?? true) === true) {
                $tokens = $this->container->get(TokenManager::class);
                if ($tokens instanceof TokenManager) {
                    $this->container->set(AuthManager::class, new AuthManager($legacyConnection, $tokens));
                }
            }
        }

        $this->router = new Router($this->container);
        $this->container->set(Router::class, $this->router);
        $this->router->addRoutes($config['routes'] ?? []);
        $projectRoot = isset($config['project_root']) ? (string) $config['project_root'] : '';
        foreach ([$projectRoot . '/routes', $projectRoot . '/src/routes'] as $applicationRoutes) {
            if ($projectRoot !== '' && is_dir($applicationRoutes)) {
                $this->router->addRoutes(Route::discover($applicationRoutes));
            }
        }

        $logPath = isset($config['log_path']) ? (string) $config['log_path'] : null;
        $logger = new Logger($logPath);
        $this->container->set(Logger::class, $logger);
        $outerMiddlewares = [];
        $customMiddlewares = [];
        foreach ($config['middlewares'] ?? [] as $middlewareClass) {
            $middleware = $this->container->get($middlewareClass);
            if (!$middleware instanceof MiddlewareInterface) {
                continue;
            }
            if ($middleware instanceof SecurityHeadersMiddleware) {
                $outerMiddlewares[] = $middleware;
            } else {
                $customMiddlewares[] = $middleware;
            }
        }
        $middlewares = [...$outerMiddlewares];
        if (($api['enabled'] ?? false) === true) {
            $middlewares[] = new RequestIdMiddleware();
            $tokens = $this->container->get(TokenManager::class);
            $middlewares[] = new ApiMiddleware($api, $tokens instanceof TokenManager ? $tokens : null);
        }
        $middlewares[] = new ErrorHandlerMiddleware((bool) ($config['debug'] ?? false), $logger);
        $rateLimit = $config['rate_limit'] ?? [];
        if (is_array($rateLimit) && ($rateLimit['enabled'] ?? false)) {
            $middlewares[] = new RateLimitMiddleware(
                (string) ($rateLimit['storage_path'] ?? sys_get_temp_dir() . '/phpaml-rate-limits'),
                (int) ($rateLimit['limit'] ?? 60),
                (int) ($rateLimit['window'] ?? 60),
                is_array($rateLimit['methods'] ?? null) ? $rateLimit['methods'] : ['POST', 'PUT', 'PATCH', 'DELETE']
            );
        }
        if (($config['type'] ?? null) !== 'api') {
            $apiPrefixes = ($api['enabled'] ?? false) === true ? [(string) ($api['prefix'] ?? '/api')] : [];
            $middlewares[] = new CsrfMiddleware(fn (): object => $this->container->get(Session::class), $apiPrefixes);
        }
        array_push($middlewares, ...$customMiddlewares);
        $this->pipeline = new MiddlewarePipeline($middlewares);
    }

    public function handle(Request $request, ?Closure $destination = null): Response
    {
        $this->container->beginScope();
        $this->container->setScoped(Request::class, $request);
        try {
            return $this->pipeline->handle(
                $request,
                $destination ?? fn (Request $request): Response => $this->router->dispatch($request)
            );
        } finally {
            $this->container->endScope();
        }
    }

    public function run(): void
    {
        $this->handle(Request::capture())->send();
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function router(): Router
    {
        return $this->router;
    }
}
