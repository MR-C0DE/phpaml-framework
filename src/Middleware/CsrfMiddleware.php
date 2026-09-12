<?php

declare(strict_types=1);

namespace PHPAML\Middleware;

use Closure;
use PHPAML\Http\Request;
use PHPAML\Http\Response;
use PHPAML\Session\Session;

final class CsrfMiddleware implements MiddlewareInterface
{
    /** @param list<string> $excludedPrefixes */
    public function __construct(private Session $session, private array $excludedPrefixes = [])
    {
    }

    public function process(Request $request, Closure $next): Response
    {
        foreach ($this->excludedPrefixes as $prefix) {
            $prefix = rtrim($prefix, '/');
            if ($prefix !== '' && ($request->path() === $prefix || str_starts_with($request->path(), $prefix . '/'))) {
                return $next($request);
            }
        }
        if (preg_match('/^Bearer\s+\S+$/i', trim((string) $request->header('Authorization', '')))) {
            return $next($request);
        }
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }
        $provided = $request->input('_token', $request->header('X-CSRF-Token'));
        if (!is_string($provided) || !hash_equals($this->session->token(), $provided)) {
            return Response::html('<h1>419</h1><p>Jeton CSRF invalide.</p>', 419)
                ->withHeader('X-CSRF-Token', $this->session->token());
        }
        return $next($request)->withHeader('X-CSRF-Token', $this->session->token());
    }
}
