<?php

declare(strict_types=1);

namespace PHPAML\Middleware;

use Closure;
use PHPAML\Api\ApiResponse;
use PHPAML\Api\TokenManager;
use PHPAML\Http\Request;
use PHPAML\Http\Response;

final class AbilityMiddleware implements MiddlewareInterface
{
    /** @param string|list<string> $abilities */
    public function __construct(private TokenManager $tokens, string|array $abilities = ['*'])
    {
        $this->abilities = is_string($abilities) ? [$abilities] : $abilities;
    }

    /** @var list<string> */
    private array $abilities;

    public function process(Request $request, Closure $next): Response
    {
        $token = $request->attribute('auth.token');
        if (!is_array($token)) { return ApiResponse::error('UNAUTHENTICATED', 'Authentification requise.', 401); }
        $abilities = $this->abilities === ['*'] ? $request->attribute('auth.required_abilities', $this->abilities) : $this->abilities;
        $abilities = is_array($abilities) ? array_values(array_filter($abilities, 'is_string')) : [(string) $abilities];
        foreach ($abilities as $ability) {
            if (!$this->tokens->can($token, $ability)) {
                return ApiResponse::error('FORBIDDEN', "Capacité requise : {$ability}.", 403);
            }
        }
        return $next($request);
    }
}
