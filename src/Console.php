<?php

declare(strict_types=1);

namespace PHPAML;

use Stringable;
use Throwable;

/** Terminal output for the application running under `aml serve`. */
final class Console
{
    private const MAX_MESSAGE_BYTES = 1048576;
    private const MAX_MESSAGE_LINES = 1000;
    private const MAX_NESTING_DEPTH = 12;
    private const MAX_ARRAY_ITEMS = 1000;
    private const SENSITIVE = ['password', 'passwd', 'secret', 'token', 'authorization', 'cookie', 'api_key', 'apikey'];

    public static function log(mixed ...$values): void
    {
        self::write('LOG', $values);
    }

    public static function info(mixed ...$values): void
    {
        self::write('INFO', $values);
    }

    public static function warning(mixed ...$values): void
    {
        self::write('WARN', $values);
    }

    public static function error(mixed ...$values): void
    {
        self::write('ERROR', $values);
    }

    /** @internal Used by the HTTP output-capture middleware. */
    public static function output(string $value): void
    {
        $value = rtrim($value, "\r\n");
        if ($value !== '') self::write('ECHO', [$value]);
    }

    /** @param list<mixed> $values */
    private static function write(string $level, array $values): void
    {
        $parts = array_map(self::format(...), $values);
        $prefix = sprintf('[PHPAML] [%s] ', $level);
        $message = str_replace(["\r\n", "\r"], "\n", implode(' ', $parts));
        $encoded = json_encode($message, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
        if (is_string($encoded)) {
            $normalized = json_decode($encoded, true);
            if (is_string($normalized)) $message = $normalized;
        }
        $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '�', $message) ?? '[invalid output]';
        if (strlen($message) > self::MAX_MESSAGE_BYTES) {
            $message = substr($message, 0, self::MAX_MESSAGE_BYTES) . '… [truncated]';
        }
        $offset = 0;
        for ($line = 1; $line < self::MAX_MESSAGE_LINES; $line++) {
            $position = strpos($message, "\n", $offset);
            if ($position === false) break;
            $offset = $position + 1;
        }
        if ($line === self::MAX_MESSAGE_LINES && ($position = strpos($message, "\n", $offset)) !== false) {
            $message = substr($message, 0, $position) . PHP_EOL . '… [lines truncated]';
        }
        $message = str_replace("\n", PHP_EOL . $prefix, $message);
        $line = $prefix . $message . PHP_EOL;
        $stream = @fopen('php://stderr', 'wb');
        if (is_resource($stream)) {
            fwrite($stream, $line);
            fclose($stream);
            return;
        }

        error_log(rtrim($line));
    }

    private static function format(mixed $value): string
    {
        if ($value === null) return 'null';
        if (is_bool($value)) return $value ? 'true' : 'false';
        if (is_string($value) || is_int($value) || is_float($value)) return (string) $value;
        if ($value instanceof Throwable) {
            return sprintf('%s: %s (%s:%d)', $value::class, $value->getMessage(), $value->getFile(), $value->getLine());
        }
        if ($value instanceof Stringable) {
            try {
                return (string) $value;
            } catch (Throwable) {
                return sprintf('[unprintable %s]', $value::class);
            }
        }

        try {
            return (string) json_encode(
                self::redact($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
            );
        } catch (Throwable) {
            return sprintf('[unprintable %s]', get_debug_type($value));
        }
    }

    private static function redact(mixed $value, int $depth = 0): mixed
    {
        if (!is_array($value)) return $value;
        if ($depth >= self::MAX_NESTING_DEPTH) return '[MAX DEPTH]';
        $count = 0;
        foreach ($value as $key => $item) {
            $count++;
            if ($count > self::MAX_ARRAY_ITEMS) {
                $value = array_slice($value, 0, self::MAX_ARRAY_ITEMS, true);
                $value['…'] = '[truncated]';
                break;
            }
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE, true)) {
                $value[$key] = '[REDACTED]';
            } else {
                $value[$key] = self::redact($item, $depth + 1);
            }
        }
        return $value;
    }
}
