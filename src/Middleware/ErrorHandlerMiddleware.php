<?php

declare(strict_types=1);

namespace PHPAML\Middleware;

use Closure;
use PHPAML\Http\Request;
use PHPAML\Http\Response;
use PHPAML\Http\HttpException;
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
            if ($error instanceof HttpException) {
                return Response::html('<h1>' . $error->statusCode() . '</h1><p>' . htmlspecialchars($error->publicMessage(), ENT_QUOTES, 'UTF-8') . '</p>', $error->statusCode());
            }
            $requestId = bin2hex(random_bytes(8));
            error_log(json_encode(['level' => 'error', 'request_id' => $requestId, 'method' => $request->method(), 'path' => $request->path(), 'exception' => $error::class, 'message' => $error->getMessage()], JSON_UNESCAPED_SLASHES));
            $message = $this->debug
                ? htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8')
                : 'Une erreur interne est survenue. Référence : ' . $requestId;

            return Response::html("<h1>Erreur 500</h1><p>{$message}</p>", 500);
        }
    }
}
