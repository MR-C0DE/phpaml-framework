<?php

declare(strict_types=1);

namespace PHPAML\Middleware;

use Closure;
use PHPAML\Api\FileIdempotencyStore;
use PHPAML\Http\Request;
use PHPAML\Http\Response;

final class IdempotencyMiddleware implements MiddlewareInterface
{
    /** @param list<string> $methods */
    public function __construct(private FileIdempotencyStore $store, private array $methods = ['POST', 'PUT', 'PATCH'])
    {
    }

    public function process(Request $request, Closure $next): Response
    {
        if (!in_array($request->method(), $this->methods, true)) {
            return $next($request);
        }
        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key === '') {
            return $next($request);
        }
        if (!preg_match('/^[A-Za-z0-9._:-]{8,255}$/', $key)) {
            return Response::json(['error' => ['code' => 'INVALID_IDEMPOTENCY_KEY', 'message' => "Clé d'idempotence invalide."]], 400);
        }
        $fingerprint = hash('sha256', $request->method() . '|' . $request->path() . '|' . serialize($request->input()));
        $handle = $this->store->lock($key . '|' . $request->path());
        try {
            $saved = $this->store->read($handle);
            if ($saved !== null) {
                if (!hash_equals((string) ($saved['fingerprint'] ?? ''), $fingerprint)) {
                    return Response::json(['error' => ['code' => 'IDEMPOTENCY_CONFLICT', 'message' => 'Cette clé a déjà servi pour une autre requête.']], 409);
                }
                $response = new Response((string) $saved['content'], (int) $saved['status'], (array) $saved['headers']);
                return $response->withHeader('Idempotency-Replayed', 'true');
            }
            $response = $next($request);
            if ($response->status() < 500) {
                $this->store->write($handle, [
                    'fingerprint' => $fingerprint,
                    'status' => $response->status(),
                    'headers' => $response->headers(),
                    'content' => $response->content(),
                ]);
            }
            return $response;
        } finally {
            $this->store->unlock($handle);
        }
    }
}
