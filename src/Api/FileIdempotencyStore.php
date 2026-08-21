<?php

declare(strict_types=1);

namespace PHPAML\Api;

final class FileIdempotencyStore
{
    public function __construct(private string $directory, private int $ttl = 86400)
    {
        if ($ttl < 1) {
            throw new \InvalidArgumentException('La durée de conservation doit être positive.');
        }
    }

    /** @return resource */
    public function lock(string $scope)
    {
        $this->ensureDirectory();
        $handle = fopen($this->path($scope), 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new \RuntimeException("Le stockage d'idempotence est indisponible.");
        }
        return $handle;
    }

    /** @param resource $handle @return null|array<string, mixed> */
    public function read($handle): ?array
    {
        rewind($handle);
        $value = json_decode(stream_get_contents($handle) ?: '', true);
        if (!is_array($value) || (int) ($value['expires_at'] ?? 0) < time()) {
            return null;
        }
        return $value;
    }

    /** @param resource $handle @param array<string, mixed> $value */
    public function write($handle, array $value): void
    {
        $value['expires_at'] = time() + $this->ttl;
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $json);
        fflush($handle);
    }

    /** @param resource $handle */
    public function unlock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function path(string $scope): string
    {
        return rtrim($this->directory, '/') . '/' . hash('sha256', $scope) . '.json';
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new \RuntimeException("Impossible de créer le stockage d'idempotence.");
        }
    }
}
