<?php

declare(strict_types=1);

namespace PHPAML\Api;

use RuntimeException;

final class TokenManager
{
    public function __construct(private string $storagePath, private int $defaultTtl = 86400) {}

    /** @param list<string> $abilities */
    public function issue(string|int $ownerId, string $name = 'api', array $abilities = ['*'], ?int $ttl = null): string
    {
        $plain = bin2hex(random_bytes(32));
        $this->mutate(function (array &$records) use ($plain, $ownerId, $name, $abilities, $ttl): void {
            $records[] = [
                'hash' => hash('sha256', $plain), 'owner_id' => (string) $ownerId,
                'name' => $name, 'abilities' => $abilities,
                'expires_at' => time() + ($ttl ?? $this->defaultTtl), 'created_at' => time(),
            ];
        });
        return $plain;
    }

    /** @return array<string, mixed>|null */
    public function authenticate(string $plain): ?array
    {
        $hash = hash('sha256', $plain);
        foreach ($this->read() as $record) {
            if (hash_equals((string) ($record['hash'] ?? ''), $hash) && (int) ($record['expires_at'] ?? 0) > time()) {
                return $record;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $token */
    public function can(array $token, string $ability): bool
    {
        $abilities = array_values(array_filter($token['abilities'] ?? [], 'is_string'));
        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }

    public function rotate(string $plain, ?int $ttl = null): ?string
    {
        $replacement = bin2hex(random_bytes(32));
        $rotated = false;
        $hash = hash('sha256', $plain);
        $this->mutate(function (array &$records) use ($hash, $replacement, $ttl, &$rotated): void {
            foreach ($records as &$record) {
                if (hash_equals((string) ($record['hash'] ?? ''), $hash) && (int) ($record['expires_at'] ?? 0) > time()) {
                    $record['hash'] = hash('sha256', $replacement);
                    $record['expires_at'] = time() + ($ttl ?? $this->defaultTtl);
                    $record['created_at'] = time();
                    $rotated = true;
                    break;
                }
            }
            unset($record);
        });
        return $rotated ? $replacement : null;
    }

    public function revoke(string $plain): bool
    {
        $hash = hash('sha256', $plain);
        $removed = false;
        $this->mutate(function (array &$records) use ($hash, &$removed): void {
            $remaining = array_values(array_filter($records, static fn (array $record): bool => !hash_equals((string) ($record['hash'] ?? ''), $hash)));
            $removed = count($remaining) !== count($records);
            $records = $remaining;
        });
        return $removed;
    }

    public function revokeOwner(string|int $ownerId, ?string $exceptPlain = null): int
    {
        $ownerId = (string) $ownerId;
        $exceptHash = $exceptPlain === null ? null : hash('sha256', $exceptPlain);
        $removed = 0;
        $this->mutate(function (array &$records) use ($ownerId, $exceptHash, &$removed): void {
            $remaining = array_values(array_filter($records, static function (array $record) use ($ownerId, $exceptHash): bool {
                if ((string) ($record['owner_id'] ?? '') !== $ownerId) { return true; }
                return $exceptHash !== null && hash_equals((string) ($record['hash'] ?? ''), $exceptHash);
            }));
            $removed = count($records) - count($remaining);
            $records = $remaining;
        });
        return $removed;
    }

    public function cleanup(): int
    {
        $removed = 0;
        $this->mutate(function (array &$records) use (&$removed): void {
            $remaining = array_values(array_filter($records, static fn (array $record): bool => (int) ($record['expires_at'] ?? 0) > time()));
            $removed = count($records) - count($remaining);
            $records = $remaining;
        });
        return $removed;
    }

    /** @return list<array<string, mixed>> */
    private function read(): array
    {
        return $this->withLock(false, fn (): array => $this->readUnlocked());
    }

    /** @param callable(list<array<string, mixed>>&): void $operation */
    private function mutate(callable $operation): void
    {
        $this->withLock(true, function () use ($operation): void {
            $records = $this->readUnlocked();
            $operation($records);
            $this->writeUnlocked($records);
        });
    }

    private function withLock(bool $exclusive, callable $operation): mixed
    {
        $directory = dirname($this->storagePath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Impossible de créer le stockage des tokens API.');
        }
        $handle = fopen($this->storagePath . '.lock', 'c+');
        if ($handle === false || !flock($handle, $exclusive ? LOCK_EX : LOCK_SH)) {
            if (is_resource($handle)) { fclose($handle); }
            throw new RuntimeException('Impossible de verrouiller le stockage des tokens API.');
        }
        try { return $operation(); }
        finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    /** @return list<array<string, mixed>> */
    private function readUnlocked(): array
    {
        if (!is_file($this->storagePath)) { return []; }
        $decoded = json_decode((string) file_get_contents($this->storagePath), true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /** @param list<array<string, mixed>> $records */
    private function writeUnlocked(array $records): void
    {
        $json = json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporary = $this->storagePath . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $json) === false || !rename($temporary, $this->storagePath)) {
            @unlink($temporary);
            throw new RuntimeException('Impossible d’enregistrer les tokens API.');
        }
    }
}
