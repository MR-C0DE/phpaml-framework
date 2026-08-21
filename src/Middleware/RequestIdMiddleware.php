<?php

declare(strict_types=1);

namespace PHPAML\Middleware;

use Closure;
use PHPAML\Http\Request;
use PHPAML\Http\Response;

final class RequestIdMiddleware implements MiddlewareInterface
{
    /** @param null|Closure(array<string, mixed>): void $observer */
    public function __construct(private ?Closure $observer = null)
    {
    }

    public function process(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->header('X-Request-ID', '');
        $requestId = preg_match('/^[A-Za-z0-9._-]{8,128}$/', $incoming)
            ? $incoming
            : bin2hex(random_bytes(16));
        $startedAt = hrtime(true);
        $response = $next($request->withAttribute('request_id', $requestId));
        $durationMs = round((hrtime(true) - $startedAt) / 1_000_000, 3);
        if ($this->observer !== null) {
            ($this->observer)([
                'request_id' => $requestId,
                'method' => $request->method(),
                'path' => $request->path(),
                'status' => $response->status(),
                'duration_ms' => $durationMs,
            ]);
        }
        return $response
            ->withHeader('X-Request-ID', $requestId)
            ->withHeader('Server-Timing', 'app;dur=' . $durationMs);
    }
}
