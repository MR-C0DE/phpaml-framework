<?php

declare(strict_types=1);

namespace PHPAML\Session;

interface SessionStoreInterface
{
    public function set(string $key, mixed $value): void;

    public function get(string $key, mixed $default = null): mixed;

    public function remove(string $key): void;

    public function regenerate(): void;

    public function close(): void;
}
