<?php

declare(strict_types=1);

namespace PHPAML;

use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

final class Container
{
    /** @var array<string, array{concrete: callable|object|string, singleton: bool}> */
    private array $bindings = [];

    /** @var array<string, object> */
    private array $instances = [];

    /** @var list<string> */
    private array $resolving = [];

    public function set(string $id, object $value): void
    {
        $this->instances[$id] = $value;
    }

    public function bind(string $id, callable|object|string $concrete, bool $singleton = false): void
    {
        $this->bindings[$id] = compact('concrete', 'singleton');
    }

    public function singleton(string $id, callable|object|string $concrete): void
    {
        $this->bind($id, $concrete, true);
    }

    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (in_array($id, $this->resolving, true)) {
            throw new RuntimeException('Dépendance circulaire détectée : ' . implode(' -> ', [...$this->resolving, $id]));
        }

        $this->resolving[] = $id;
        try {
            $binding = $this->bindings[$id] ?? null;
            $concrete = $binding['concrete'] ?? $id;
            if (is_object($concrete) && !is_callable($concrete)) {
                $instance = $concrete;
            } elseif (is_callable($concrete)) {
                $instance = $concrete($this);
            } else {
                $instance = $this->build((string) $concrete);
            }
            if (!is_object($instance)) {
                throw new RuntimeException("Le service '{$id}' doit produire un objet.");
            }
            if (($binding['singleton'] ?? false) === true) {
                $this->instances[$id] = $instance;
            }
            return $instance;
        } finally {
            array_pop($this->resolving);
        }
    }

    private function build(string $class): object
    {
        if (!class_exists($class)) {
            throw new RuntimeException("Le service '{$class}' est introuvable.");
        }

        $reflection = new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new RuntimeException("Le service '{$class}' ne peut pas être instancié.");
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();
                    continue;
                }
                throw new RuntimeException("Impossible de résoudre '{$parameter->getName()}' pour '{$class}'.");
            }
            $arguments[] = $this->get($type->getName());
        }

        return $reflection->newInstanceArgs($arguments);
    }
}
