<?php

declare(strict_types=1);

namespace PHPAML\Session;

final class Session
{
    private function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            session_start();
        }
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();
        return $_SESSION[$key] ?? $default;
    }

    public function remove(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        $this->start();
        session_regenerate_id(true);
    }

    public function token(): string
    {
        $token = $this->get('_csrf_token');
        if (!is_string($token)) {
            $token = bin2hex(random_bytes(32));
            $this->set('_csrf_token', $token);
        }
        return $token;
    }
}
