<?php

declare(strict_types=1);

namespace PHPAML\Middleware;

use Closure;
use PHPAML\Http\Request;
use PHPAML\Http\Response;
use PHPAML\Http\HttpException;
use PHPAML\Http\ErrorPageRenderer;
use PHPAML\Logging\Logger;
use PHPAML\Api\ApiResponse;
use PHPAML\Api\ApiValidationException;
use Throwable;

final class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private bool $debug = false,
        private ?Logger $logger = null,
        private ?ErrorPageRenderer $renderer = null,
    ) {
    }

    public function process(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
            if (!$request->expectsJson() && $response->status() >= 400 && $this->renderer !== null) {
                $rendered = $this->renderer->render($response->status(), $this->messageFor($response->status()));
                foreach ($response->headers() as $name => $value) {
                    if (strtolower($name) !== 'content-type') {
                        $rendered = $rendered->withHeader($name, $value);
                    }
                }
                return $rendered;
            }
            return $response;
        } catch (Throwable $error) {
            if ($error instanceof ApiValidationException) {
                return ApiResponse::validation($error->errors());
            }
            if ($error instanceof HttpException) {
                return $request->expectsJson()
                    ? ApiResponse::error($error->errorCode(), $error->publicMessage(), $error->statusCode())
                    : ($this->renderer ?? new ErrorPageRenderer())->render(
                        $error->statusCode(),
                        $error->publicMessage(),
                    );
            }
            $requestId = bin2hex(random_bytes(8));
            ($this->logger ?? new Logger())->log('error', 'Unhandled application exception', [
                'request_id' => $requestId,
                'method' => $request->method(),
                'path' => $request->path(),
                'exception' => $error::class,
                'error_message' => $error->getMessage(),
                'file' => $error->getFile(),
                'line' => $error->getLine(),
            ]);
            $message = $this->debug ? $error->getMessage() : 'Une erreur interne est survenue.';

            return $request->expectsJson()
                ? ApiResponse::error('INTERNAL_ERROR', $message, 500, ['request_id' => $requestId])
                : ($this->renderer ?? new ErrorPageRenderer())->render(
                    500,
                    $message,
                    $requestId,
                    $error,
                    $this->debug,
                );
        }
    }

    private function messageFor(int $status): string
    {
        return match ($status) {
            400 => 'The request could not be understood.',
            401 => 'Authentication is required.',
            403 => 'You are not allowed to access this resource.',
            404 => 'The requested page could not be found.',
            405 => 'This method is not allowed for the requested resource.',
            419 => 'Your session has expired. Please try again.',
            422 => 'The submitted data could not be processed.',
            429 => 'Too many requests. Please try again later.',
            503 => 'The service is temporarily unavailable.',
            default => 'An application error occurred.',
        };
    }
}
