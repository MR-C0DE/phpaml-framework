<?php

declare(strict_types=1);

namespace PHPAML\Http;

use Throwable;

final class ErrorPageRenderer
{
    public function __construct(private ?string $viewsPath = null)
    {
    }

    public function render(
        int $status,
        string $message,
        ?string $requestId = null,
        ?Throwable $error = null,
        bool $debug = false,
    ): Response {
        $view = $this->viewFor($status);
        if ($view === null) {
            return Response::html($this->fallback($status, $message, $requestId, $error, $debug), $status);
        }

        $title = $this->titleFor($status);
        ob_start();
        try {
            require $view;
            $content = (string) ob_get_clean();
        } catch (Throwable $renderingError) {
            ob_end_clean();
            $content = $this->fallback($status, $message, $requestId, $error ?? $renderingError, $debug);
        }

        return Response::html($content, $status);
    }

    private function viewFor(int $status): ?string
    {
        if ($this->viewsPath === null || $this->viewsPath === '') {
            return null;
        }

        foreach ([$status . '.php', 'default.php'] as $filename) {
            $path = rtrim($this->viewsPath, '/\\') . DIRECTORY_SEPARATOR . $filename;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function fallback(
        int $status,
        string $message,
        ?string $requestId,
        ?Throwable $error,
        bool $debug,
    ): string {
        $details = '';
        if ($debug && $error !== null) {
            $details = '<pre>' . htmlspecialchars(
                $error::class . ': ' . $error->getMessage() . PHP_EOL
                . $error->getFile() . ':' . $error->getLine() . PHP_EOL . PHP_EOL
                . $error->getTraceAsString(),
                ENT_QUOTES,
                'UTF-8',
            ) . '</pre>';
        }
        $reference = $requestId === null
            ? ''
            : '<p>Reference: <code>' . htmlspecialchars($requestId, ENT_QUOTES, 'UTF-8') . '</code></p>';

        return '<h1>' . $status . ' - ' . htmlspecialchars($this->titleFor($status), ENT_QUOTES, 'UTF-8') . '</h1>'
            . '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . $reference
            . $details;
    }

    private function titleFor(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Page Not Found',
            405 => 'Method Not Allowed',
            419 => 'Page Expired',
            422 => 'Unprocessable Content',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable',
            default => 'Application Error',
        };
    }
}
