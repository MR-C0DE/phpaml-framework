<?php

declare(strict_types=1);

namespace PHPAML\Mvc;

use PHPAML\Http\Response;
use RuntimeException;
use PHPAML\Session\Session;

final class View
{
    public function __construct(private string $directory, private ?Session $session = null)
    {
    }

    public function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function csrfField(): string
    {
        $token = $this->session?->token() ?? '';
        return '<input type="hidden" name="_token" value="' . $this->escape($token) . '">';
    }

    /** @param array<string, mixed> $data */
    public function render(string $name, array $data = [], int $status = 200): Response
    {
        $basePath = realpath($this->directory);
        $viewPath = $basePath === false ? false : realpath($basePath . DIRECTORY_SEPARATOR . $name);

        if (
            $basePath === false
            || $viewPath === false
            || !str_starts_with($viewPath, $basePath . DIRECTORY_SEPARATOR)
            || !is_file($viewPath)
        ) {
            throw new RuntimeException("La vue '{$name}' est introuvable.");
        }

        ob_start();
        extract($data, EXTR_SKIP);
        include $viewPath;
        return Response::html((string) ob_get_clean(), $status);
    }

    /** @param array<string, mixed> $data */
    public function partial(string $name, array $data = []): void
    {
        $partialsPath = realpath($this->directory . DIRECTORY_SEPARATOR . 'partials');
        $partialPath = $partialsPath === false
            ? false
            : realpath($partialsPath . DIRECTORY_SEPARATOR . $name);

        if (
            $partialsPath === false
            || $partialPath === false
            || !str_starts_with($partialPath, $partialsPath . DIRECTORY_SEPARATOR)
            || !is_file($partialPath)
        ) {
            throw new RuntimeException("La vue partielle '{$name}' est introuvable.");
        }

        extract($data, EXTR_SKIP);
        include $partialPath;
    }
}
