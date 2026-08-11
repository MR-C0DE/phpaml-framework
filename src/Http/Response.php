<?php

declare(strict_types=1);

namespace PHPAML\Http;

use JsonException;
use RuntimeException;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        private string $content = '',
        private int $status = 200,
        private array $headers = ['Content-Type' => 'text/html; charset=UTF-8']
    ) {
    }

    /** @param array<string, string> $headers */
    public static function html(string $content, int $status = 200, array $headers = []): self
    {
        return new self($content, $status, array_merge(
            ['Content-Type' => 'text/html; charset=UTF-8'],
            $headers
        ));
    }

    public static function json(mixed $data, int $status = 200): self
    {
        try {
            $content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('La réponse JSON ne peut pas être encodée.', 0, $error);
        }
        return new self($content, $status, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        if (!str_starts_with($location, '/') || str_starts_with($location, '//') || preg_match('/[\r\n]/', $location)) {
            throw new RuntimeException('La redirection doit utiliser un chemin interne valide.');
        }
        if (!in_array($status, [301, 302, 303, 307, 308], true)) {
            throw new RuntimeException('Statut de redirection invalide.');
        }
        return new self('', $status, ['Location' => $location]);
    }

    public function content(): string
    {
        return $this->content;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function withHeader(string $name, string $value): self
    {
        if (!preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) || preg_match('/[\r\n]/', $value)) {
            throw new RuntimeException('En-tête HTTP invalide.');
        }
        $response = clone $this;
        $response->headers[$name] = $value;
        return $response;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        echo $this->content;
    }
}
