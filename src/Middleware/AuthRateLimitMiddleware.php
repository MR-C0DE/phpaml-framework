<?php
declare(strict_types=1);
namespace PHPAML\Middleware;
use Closure;
use PHPAML\Http\Request;
use PHPAML\Http\Response;
final class AuthRateLimitMiddleware implements MiddlewareInterface
{
    private RateLimitMiddleware $limiter;
    public function __construct() { $this->limiter = new RateLimitMiddleware(sys_get_temp_dir() . '/phpaml-auth-rate-limits', 5, 60, ['POST']); }
    public function process(Request $request, Closure $next): Response { return $this->limiter->process($request, $next); }
}
