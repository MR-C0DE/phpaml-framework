<?php

declare(strict_types=1);

namespace PHPAML\Middleware;

use Closure;
use PHPAML\Http\Request;
use PHPAML\Http\Response;
use PHPAML\Security\CspNonce;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Closure $next): Response
    {
        $nonce = CspNonce::generate();
        $response = $next($request->withAttribute(CspNonce::ATTRIBUTE, $nonce));
        $scriptSource = "script-src 'self' 'nonce-{$nonce}'";
        $response = $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Content-Security-Policy', "default-src 'self'; {$scriptSource}; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; object-src 'none'")
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        return $https ? $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains') : $response;
    }
}
