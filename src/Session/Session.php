<?php

declare(strict_types=1);

namespace PHPAML\Session;

use PHPAML\ScopeCleanupInterface;

final class Session implements ScopeCleanupInterface
{
    private SessionStoreInterface $store;

    /** @param array{lifetime?: int, same_site?: string, secure?: bool} $config */
    public function __construct(array $config = [], ?SessionStoreInterface $store = null)
    {
        $this->store = $store ?? new NativeSessionStore($config);
    }

    public function set(string $key, mixed $value): void
    {
        $this->store->set($key, $value);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store->get($key, $default);
    }

    public function remove(string $key): void
    {
        $this->store->remove($key);
    }

    public function regenerate(): void
    {
        $this->store->regenerate();
    }

    public function id(): ?string
    {
        return $this->store instanceof IdentifiedSessionStoreInterface ? $this->store->id() : null;
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

    public function csrfMeta(): string
    {
        return '<meta name="csrf-token" content="'
            . htmlspecialchars($this->token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '">';
    }

    public function endScope(): void
    {
        $this->store->close();
    }
}
