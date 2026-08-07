<?php

declare(strict_types=1);

namespace PHPAML\Middleware;

use Closure;
use PHPAML\Http\Request;
use PHPAML\Http\Response;

interface MiddlewareInterface
{
    /** @param Closure(Request): Response $next */
    public function process(Request $request, Closure $next): Response;
}
