<?php

declare(strict_types=1);

namespace PHPAML\Middleware;

use Closure;
use PHPAML\Http\Request;
use PHPAML\Http\Response;
use PHPAML\Http\HttpException;
use PHPAML\Logging\Logger;
use PHPAML\Api\ApiResponse;
use PHPAML\Api\ApiValidationException;
use Throwable;

final class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(private bool $debug = false, private ?Logger $logger = null)
    {
    }

    public function process(Request $request, Closure $next): Response
    {
        try {
            return $next($request);
        } catch (Throwable $error) {
            if ($error instanceof ApiValidationException) {
                return ApiResponse::validation($error->errors());
            }
            if ($error instanceof HttpException) {
                return $request->expectsJson()
                    ? ApiResponse::error($error->errorCode(), $error->publicMessage(), $error->statusCode())
                    : Response::html('<h1>' . $error->statusCode() . '</h1><p>' . htmlspecialchars($error->publicMessage(), ENT_QUOTES, 'UTF-8') . '</p>', $error->statusCode());
            }
            $requestId = bin2hex(random_bytes(8));
            ($this->logger ?? new Logger())->log('error', 'Unhandled application exception', [
                'request_id' => $requestId,
                'method' => $request->method(),
                'path' => $request->path(),
                'exception' => $error::class,
                'error_message' => $error->getMessage(),
            ]);
            $message = $this->debug
                ? htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8')
                : 'Une erreur interne est survenue. Référence : ' . $requestId;

            return $request->expectsJson()
                ? ApiResponse::error('INTERNAL_ERROR', strip_tags($message), 500, ['request_id' => $requestId])
                : Response::html("<h1>Erreur 500</h1><p>{$message}</p>", 500);
        }
    }
}
