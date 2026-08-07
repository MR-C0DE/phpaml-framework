<?php

declare(strict_types=1);

namespace PHPAML;

final class Autoloader
{
    /** @param array<string, string> $prefixes */
    public static function register(array $prefixes): void
    {
        spl_autoload_register(static function (string $class) use ($prefixes): void {
            foreach ($prefixes as $prefix => $directory) {
                if (!str_starts_with($class, $prefix)) {
                    continue;
                }

                $relativeClass = substr($class, strlen($prefix));
                $path = rtrim($directory, DIRECTORY_SEPARATOR)
                    . DIRECTORY_SEPARATOR
                    . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass)
                    . '.php';

                if (is_file($path)) {
                    require_once $path;
                }

                return;
            }
        });
    }
}
