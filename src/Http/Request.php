<?php

declare(strict_types=1);

namespace PHPAML\Http;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, mixed> $server
     * @param array<string, string> $cookies
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        private string $method,
        private string $path,
        private array $query = [],
        private array $body = [],
        private array $server = [],
        private array $cookies = [],
        private array $attributes = []
    ) {
        $this->method = strtoupper($method);
        $this->path = '/' . ltrim($path, '/');
    }

    public static function capture(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $body = $_POST;
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        $rawBody = file_get_contents('php://input') ?: '';
        if (str_contains($contentType, 'application/json') && $rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            $body = is_array($decoded) ? $decoded : [];
        } elseif ($body === [] && $rawBody !== '') {
            parse_str($rawBody, $body);
        }
        return new self(
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            (string) (parse_url($uri, PHP_URL_PATH) ?: '/'),
            $_GET,
            $body,
            $_SERVER,
            $_COOKIE
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path === '' ? '/' : (rtrim($this->path, '/') ?: '/');
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->query : ($this->query[$key] ?? $default);
    }

    public function input(?string $key = null, mixed $default = null): mixed
    {
        $input = array_merge($this->query, $this->body);
        return $key === null ? $input : ($input[$key] ?? $default);
    }

    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    public function header(string $name, mixed $default = null): mixed
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $this->server[$key] ?? $default;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $request = clone $this;
        $request->attributes[$key] = $value;
        return $request;
    }
}
