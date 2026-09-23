<?php

declare(strict_types=1);

namespace PHPAML\Session;

final class RedisSessionStore implements IdentifiedSessionStoreInterface
{
    public function __construct(
        private object $redis,
        private string $sessionId,
        private int $ttl = 7200,
        private string $prefix = 'phpaml:session:',
    ) {
        foreach (['hGet', 'hSet', 'hDel', 'expire', 'exists', 'rename'] as $method) {
            if (!method_exists($redis, $method)) {
                throw new \InvalidArgumentException("Le client Redis doit fournir {$method}().");
            }
        }
        if ($sessionId === '' || $ttl < 1) {
            throw new \InvalidArgumentException('Identifiant de session ou durée Redis invalide.');
        }
    }

    public static function generateId(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function id(): string
    {
        return $this->sessionId;
    }

    public function set(string $key, mixed $value): void
    {
        $this->redis->hSet($this->key(), $this->field($key), serialize($value));
        $this->redis->expire($this->key(), $this->ttl);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->redis->hGet($this->key(), $this->field($key));
        if ($value === false || $value === null) {
            return $default;
        }
        $decoded = @unserialize((string) $value, ['allowed_classes' => false]);
        return $decoded === false && $value !== serialize(false) ? $default : $decoded;
    }

    public function remove(string $key): void
    {
        $this->redis->hDel($this->key(), $this->field($key));
        $this->redis->expire($this->key(), $this->ttl);
    }

    public function regenerate(): void
    {
        $oldKey = $this->key();
        $this->sessionId = self::generateId();
        if ((int) $this->redis->exists($oldKey) > 0) {
            $this->redis->rename($oldKey, $this->key());
            $this->redis->expire($this->key(), $this->ttl);
        }
    }

    public function close(): void
    {
    }

    private function key(): string
    {
        return $this->prefix . hash('sha256', $this->sessionId);
    }

    private function field(string $key): string
    {
        return hash('sha256', $key);
    }
}
