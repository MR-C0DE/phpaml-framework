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
        $records = $this->read();
        $records[] = [
            'hash' => hash('sha256', $plain), 'owner_id' => (string) $ownerId,
            'name' => $name, 'abilities' => $abilities,
            'expires_at' => time() + ($ttl ?? $this->defaultTtl), 'created_at' => time(),
        ];
        $this->write($records);
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

    public function can(array $token, string $ability): bool
    {
        $abilities = array_values(array_filter($token['abilities'] ?? [], 'is_string'));
        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }

    public function rotate(string $plain, ?int $ttl = null): ?string
    {
        $token = $this->authenticate($plain);
        if ($token === null) { return null; }
        $replacement = $this->issue($token['owner_id'], (string) ($token['name'] ?? 'api'), $token['abilities'] ?? ['*'], $ttl);
        $this->revoke($plain);
        return $replacement;
    }

    public function revoke(string $plain): bool
    {
        $hash = hash('sha256', $plain);
        $records = $this->read();
        $remaining = array_values(array_filter($records, static fn (array $record): bool => !hash_equals((string) ($record['hash'] ?? ''), $hash)));
        if (count($remaining) === count($records)) { return false; }
        $this->write($remaining);
        return true;
    }

    public function revokeOwner(string|int $ownerId, ?string $exceptPlain = null): int
    {
        $ownerId = (string) $ownerId;
        $exceptHash = $exceptPlain === null ? null : hash('sha256', $exceptPlain);
        $records = $this->read();
        $remaining = array_values(array_filter($records, static function (array $record) use ($ownerId, $exceptHash): bool {
            if ((string) ($record['owner_id'] ?? '') !== $ownerId) { return true; }
            return $exceptHash !== null && hash_equals((string) ($record['hash'] ?? ''), $exceptHash);
        }));
        $removed = count($records) - count($remaining);
        if ($removed > 0) { $this->write($remaining); }
        return $removed;
    }

    public function cleanup(): int
    {
        $records = $this->read();
        $remaining = array_values(array_filter($records, static fn (array $record): bool => (int) ($record['expires_at'] ?? 0) > time()));
        $removed = count($records) - count($remaining);
        if ($removed > 0) { $this->write($remaining); }
        return $removed;
    }

    /** @return list<array<string, mixed>> */
    private function read(): array
    {
        if (!is_file($this->storagePath)) { return []; }
        $decoded = json_decode((string) file_get_contents($this->storagePath), true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /** @param list<array<string, mixed>> $records */
    private function write(array $records): void
    {
        $directory = dirname($this->storagePath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Impossible de créer le stockage des tokens API.');
        }
        $json = json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->storagePath, $json, LOCK_EX) === false) {
            throw new RuntimeException('Impossible d’enregistrer les tokens API.');
        }
    }
}
