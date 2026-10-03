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
        // AML View layout modifiers currently render trusted style attributes.
        // Keep scripts nonce-protected while allowing those presentation-only
        // attributes until View can emit every modifier through stylesheets.
        $styleSource = "style-src 'self' 'unsafe-inline'";
        $response = $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Content-Security-Policy', "default-src 'self'; {$scriptSource}; {$styleSource}; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; object-src 'none'")
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        return $request->isSecure()
            ? $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
            : $response;
    }
}
