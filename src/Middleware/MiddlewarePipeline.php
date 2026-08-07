<?php

declare(strict_types=1);

namespace PHPAML\Middleware;

use Closure;
use PHPAML\Http\Request;
use PHPAML\Http\Response;

final class MiddlewarePipeline
{
    /** @param list<MiddlewareInterface> $middlewares */
    public function __construct(private array $middlewares = [])
    {
    }

    /** @param Closure(Request): Response $destination */
    public function handle(Request $request, Closure $destination): Response
    {
        $next = array_reduce(
            array_reverse($this->middlewares),
            static fn (Closure $next, MiddlewareInterface $middleware): Closure =>
                static fn (Request $request): Response => $middleware->process($request, $next),
            $destination
        );

        return $next($request);
    }
}
