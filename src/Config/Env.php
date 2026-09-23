<?php

declare(strict_types=1);

namespace PHPAML\Config;

final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    public static function load(string $path): void
    {
        self::$values = self::read($path);
    }

    /** @return array<string, string> */
    public static function read(string $path): array
    {
        $values = [];
        if (!is_file($path)) {
            return $values;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $values[$key] = trim($value, "\"'");
        }
        return $values;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$values[$key] ?? $_ENV[$key] ?? getenv($key);
        return $value === false ? $default : $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
