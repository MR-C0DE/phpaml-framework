<?php
declare(strict_types=1);
namespace PHPAML\Middleware;
use Closure;
use PHPAML\Http\Request;
use PHPAML\Http\Response;

/** Adaptateur distribué compatible avec ext-redis et les clients exposant incr/expire/ttl. */
final class RedisRateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(private object $redis, private int $limit = 60, private int $window = 60, private string $prefix = 'phpaml:rate:')
    {
        if ($limit < 1 || $window < 1 || !method_exists($redis, 'incr') || !method_exists($redis, 'expire')) throw new \InvalidArgumentException('Client Redis ou limite invalide.');
    }
    public function process(Request $request, Closure $next): Response
    {
        $identity = (string) $request->attribute('auth.id', $request->server('REMOTE_ADDR', 'unknown'));
        $key = $this->prefix . hash('sha256', $identity . '|' . $request->method() . '|' . $request->path());
        $count = (int) $this->redis->incr($key);
        if ($count === 1) $this->redis->expire($key, $this->window);
        if ($count > $this->limit) {
            $ttl = method_exists($this->redis, 'ttl') ? max(1, (int) $this->redis->ttl($key)) : $this->window;
            return Response::json(['error' => ['code' => 'RATE_LIMITED', 'message' => 'Trop de requêtes.']], 429)->withHeader('Retry-After', (string) $ttl);
        }
        return $next($request)->withHeader('X-RateLimit-Limit', (string) $this->limit)->withHeader('X-RateLimit-Remaining', (string) max(0, $this->limit - $count));
    }
}
