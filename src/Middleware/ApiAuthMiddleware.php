<?php

declare(strict_types=1);

namespace PHPAML\Middleware;

use Closure;
use PHPAML\Api\ApiResponse;
use PHPAML\Api\TokenManager;
use PHPAML\Http\Request;
use PHPAML\Http\Response;

final class ApiAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private TokenManager $tokens) {}

    public function process(Request $request, Closure $next): Response
    {
        $authorization = trim((string) $request->header('Authorization', ''));
        if (!preg_match('/^Bearer\s+([^\s]+)$/i', $authorization, $matches)) {
            return ApiResponse::error('UNAUTHENTICATED', 'Un token Bearer est requis.', 401)->withHeader('WWW-Authenticate', 'Bearer');
        }
        $token = $this->tokens->authenticate($matches[1]);
        if ($token === null) {
            return ApiResponse::error('INVALID_TOKEN', 'Le token est invalide ou expiré.', 401)->withHeader('WWW-Authenticate', 'Bearer');
        }
        return $next($request->withAttribute('auth.id', $token['owner_id'])->withAttribute('auth.token', $token));
    }
}
