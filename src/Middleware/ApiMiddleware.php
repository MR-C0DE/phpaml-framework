<?php

declare(strict_types=1);

namespace PHPAML\Middleware;

use Closure;
use PHPAML\Api\ApiResponse;
use PHPAML\Http\Request;
use PHPAML\Http\Response;
use PHPAML\Api\FileIdempotencyStore;

final class ApiMiddleware implements MiddlewareInterface
{
    /** @param array<string, mixed> $config */
    public function __construct(private array $config = []) {}

    public function process(Request $request, Closure $next): Response
    {
        $prefix = rtrim((string) ($this->config['prefix'] ?? '/api'), '/');
        if ($request->path() !== $prefix && !str_starts_with($request->path(), $prefix . '/')) { return $next($request); }
        if ($request->method() === 'OPTIONS') { return $this->cors(new Response('', 204, []), $request); }
        $production = is_array($this->config['production'] ?? null) ? $this->config['production'] : [];
        $middlewares = [];
        $version = is_array($this->config['version'] ?? null) ? $this->config['version'] : ['name' => 'v1'];
        $middlewares[] = new ApiVersionMiddleware(
            (string) ($version['name'] ?? 'v1'),
            isset($version['deprecation']) ? (string) $version['deprecation'] : null,
            isset($version['sunset']) ? (string) $version['sunset'] : null,
            isset($version['successor']) ? (string) $version['successor'] : null,
        );
        if (($production['idempotency'] ?? true) === true) {
            $directory = (string) ($production['idempotency_path'] ?? sys_get_temp_dir() . '/phpaml-idempotency');
            $middlewares[] = new IdempotencyMiddleware(new FileIdempotencyStore($directory, (int) ($production['idempotency_ttl'] ?? 86400)));
        }
        if (($production['http_cache'] ?? true) === true) {
            $middlewares[] = new HttpCacheMiddleware((int) ($production['cache_max_age'] ?? 0), (bool) ($production['cache_public'] ?? false));
        }
        $response = (new MiddlewarePipeline($middlewares))->handle($request, $next);
        if ($response->status() >= 400 && !str_contains(strtolower((string) ($response->headers()['Content-Type'] ?? '')), 'json')) {
            $messages = [404 => 'Ressource introuvable.', 405 => 'Méthode non autorisée.', 419 => 'Jeton CSRF invalide.'];
            $codes = [404 => 'NOT_FOUND', 405 => 'METHOD_NOT_ALLOWED', 419 => 'CSRF_TOKEN_MISMATCH'];
            $response = ApiResponse::error($codes[$response->status()] ?? 'HTTP_ERROR', $messages[$response->status()] ?? 'La requête a échoué.', $response->status());
        }
        return $this->cors($response, $request);
    }

    private function cors(Response $response, Request $request): Response
    {
        $cors = is_array($this->config['cors'] ?? null) ? $this->config['cors'] : [];
        $allowed = is_array($cors['origins'] ?? null) ? $cors['origins'] : [];
        $origin = (string) $request->header('Origin', '');
        if ($origin !== '' && (in_array('*', $allowed, true) || in_array($origin, $allowed, true))) {
            $response = $response->withHeader('Access-Control-Allow-Origin', in_array('*', $allowed, true) ? '*' : $origin)->withHeader('Vary', 'Origin');
        }
        return $response
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $cors['methods'] ?? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $cors['headers'] ?? ['Accept', 'Content-Type', 'Authorization']))
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
