<?php

declare(strict_types=1);

namespace PHPAML\Http;

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
        return new self(
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }

    public static function redirect(string $location, int $status = 302): self
    {
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
