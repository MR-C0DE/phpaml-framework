<?php

declare(strict_types=1);

namespace PHPAML\Middleware;

use Closure;
use PHPAML\Http\Request;
use PHPAML\Http\Response;
use Throwable;

final class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(private bool $debug = false)
    {
    }

    public function process(Request $request, Closure $next): Response
    {
        try {
            return $next($request);
        } catch (Throwable $error) {
            $message = $this->debug
                ? htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8')
                : 'Une erreur interne est survenue.';

            return Response::html("<h1>Erreur 500</h1><p>{$message}</p>", 500);
        }
    }
}
