<?php

declare(strict_types=1);

namespace PHPAML\Http;

final class Cookie
{
    public static function set(string $name, string $value, int $expires = 0, array $options = []): bool
    {
        return setcookie($name, $value, [
            'expires' => $expires,
            'path' => $options['path'] ?? '/',
            'domain' => $options['domain'] ?? '',
            'secure' => $options['secure'] ?? true,
            'httponly' => $options['httponly'] ?? true,
            'samesite' => $options['samesite'] ?? 'Lax',
        ]);
    }

    public static function delete(string $name): bool
    {
        return self::set($name, '', time() - 3600);
    }
}
