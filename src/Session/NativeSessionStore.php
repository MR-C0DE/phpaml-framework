<?php

declare(strict_types=1);

namespace PHPAML\Session;

final class NativeSessionStore implements SessionStoreInterface
{
    /** @param array{lifetime?: int, same_site?: string, secure?: bool} $config */
    public function __construct(private array $config = [])
    {
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

    public function close(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    private function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || headers_sent()) {
            return;
        }

        $sameSite = $this->config['same_site'] ?? 'Lax';
        if (!in_array($sameSite, ['Lax', 'Strict'], true)) {
            $sameSite = 'Lax';
        }
        session_set_cookie_params([
            'lifetime' => max(0, (int) ($this->config['lifetime'] ?? 7200)),
            'path' => '/',
            'secure' => (bool) ($this->config['secure'] ?? false),
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();
    }
}
