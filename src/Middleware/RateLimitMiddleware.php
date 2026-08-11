<?php

declare(strict_types=1);

namespace PHPAML\Middleware;

use Closure;
use PHPAML\Http\Request;
use PHPAML\Http\Response;

final class RateLimitMiddleware implements MiddlewareInterface
{
    /** @param list<string> $methods */
    public function __construct(
        private string $storagePath,
        private int $limit = 60,
        private int $windowSeconds = 60,
        private array $methods = ['POST', 'PUT', 'PATCH', 'DELETE']
    ) {
        if ($this->limit < 1 || $this->windowSeconds < 1) {
            throw new \InvalidArgumentException('La limite et la fenêtre doivent être positives.');
        }
    }

    public function process(Request $request, Closure $next): Response
    {
        if (!in_array($request->method(), $this->methods, true)) {
            return $next($request);
        }
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0750, true);
        }
        $client = (string) $request->server('REMOTE_ADDR', 'unknown');
        $key = hash('sha256', $client . '|' . $request->method() . '|' . $request->path());
        $file = rtrim($this->storagePath, '/') . '/' . $key . '.json';
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Le stockage de limitation est indisponible.');
        }
        flock($handle, LOCK_EX);
        $raw = stream_get_contents($handle);
        $state = json_decode($raw ?: '', true);
        $now = time();
        $startedAt = is_array($state) ? (int) ($state['started_at'] ?? 0) : 0;
        $count = is_array($state) ? (int) ($state['count'] ?? 0) : 0;
        if ($startedAt <= 0 || $now - $startedAt >= $this->windowSeconds) {
            $startedAt = $now;
            $count = 0;
        }
        $count++;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) json_encode(['started_at' => $startedAt, 'count' => $count]));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        if ($count > $this->limit) {
            $retryAfter = max(1, $this->windowSeconds - ($now - $startedAt));
            return Response::json(['error' => 'Trop de requêtes. Réessayez plus tard.'], 429)
                ->withHeader('Retry-After', (string) $retryAfter);
        }
        return $next($request);
    }
}
