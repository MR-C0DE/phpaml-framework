<?php

declare(strict_types=1);

namespace PHPAML\Logging;

final class Logger
{
    private const SENSITIVE = ['password', 'passwd', 'secret', 'token', 'authorization', 'cookie', 'api_key', 'apikey'];

    public function __construct(private ?string $file = null)
    {
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        $record = [
            'timestamp' => gmdate(DATE_ATOM),
            'level' => strtolower($level),
            'message' => $message,
            'context' => $this->redact($context),
        ];
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($this->file !== null) {
            $directory = dirname($this->file);
            if (!is_dir($directory)) {
                mkdir($directory, 0750, true);
            }
            file_put_contents($this->file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
            return;
        }
        error_log((string) $line);
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            $normalized = strtolower((string) $key);
            if (in_array($normalized, self::SENSITIVE, true)) {
                $values[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }
        return $values;
    }
}
