<?php

declare(strict_types=1);

namespace PHPAML\Session;

final class Session
{
    /** @param array{lifetime?: int, same_site?: string, secure?: bool} $config */
    public function __construct(private array $config = []) {}

    private function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            $sameSite = $this->config['same_site'] ?? 'Lax';
            if (!in_array($sameSite, ['Lax', 'Strict'], true)) {
                $sameSite = 'Lax';
            }
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
            session_set_cookie_params([
                'lifetime' => max(0, (int) ($this->config['lifetime'] ?? 7200)),
                'path' => '/',
                'secure' => (bool) ($this->config['secure'] ?? $https),
                'httponly' => true,
                'samesite' => $sameSite,
            ]);
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
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
