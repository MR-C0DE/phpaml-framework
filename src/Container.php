<?php

declare(strict_types=1);

namespace PHPAML;

use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

final class Container
{
    /** @var array<string, array{concrete: callable|object|string, singleton: bool, scoped: bool}> */
    private array $bindings = [];

    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, list<string>> */
    private array $resolving = [];

    /** @var array<string, list<array<string, object>>> */
    private array $scopes = [];

    public function set(string $id, object $value): void
    {
        $this->instances[$id] = $value;
    }

    public function bind(string $id, callable|object|string $concrete, bool $singleton = false): void
    {
        $this->bindings[$id] = ['concrete' => $concrete, 'singleton' => $singleton, 'scoped' => false];
    }

    public function singleton(string $id, callable|object|string $concrete): void
    {
        $this->bind($id, $concrete, true);
    }

    public function scoped(string $id, callable|object|string $concrete): void
    {
        $this->bindings[$id] = ['concrete' => $concrete, 'singleton' => false, 'scoped' => true];
    }

    public function beginScope(): void
    {
        $id = $this->executionId();
        $this->scopes[$id] ??= [];
        $this->scopes[$id][] = [];
    }

    public function endScope(): void
    {
        $id = $this->executionId();
        if (($this->scopes[$id] ?? []) === []) {
            throw new RuntimeException('Aucune portée de dépendances active à fermer.');
        }
        $scope = array_pop($this->scopes[$id]);
        if ($this->scopes[$id] === []) {
            unset($this->scopes[$id]);
        }
        $closed = [];
        foreach (array_reverse($scope) as $service) {
            if (!$service instanceof ScopeCleanupInterface) {
                continue;
            }
            $objectId = spl_object_id($service);
            if (isset($closed[$objectId])) {
                continue;
            }
            $closed[$objectId] = true;
            $service->endScope();
        }
    }

    public function setScoped(string $id, object $value): void
    {
        $executionId = $this->executionId();
        $index = array_key_last($this->scopes[$executionId] ?? []);
        if ($index === null) {
            throw new RuntimeException("Le service scoped '{$id}' exige une portée active.");
        }
        $this->scopes[$executionId][$index][$id] = $value;
    }

    public function hasActiveScope(): bool
    {
        return ($this->scopes[$this->executionId()] ?? []) !== [];
    }

    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        $executionId = $this->executionId();
        $scopeIndex = array_key_last($this->scopes[$executionId] ?? []);
        if ($scopeIndex !== null && isset($this->scopes[$executionId][$scopeIndex][$id])) {
            return $this->scopes[$executionId][$scopeIndex][$id];
        }

        $resolutionStack = $this->resolving[$executionId] ?? [];
        if (in_array($id, $resolutionStack, true)) {
            throw new RuntimeException('Dépendance circulaire détectée : ' . implode(' -> ', [...$resolutionStack, $id]));
        }

        $this->resolving[$executionId][] = $id;
        try {
            $binding = $this->bindings[$id] ?? null;
            if (($binding['scoped'] ?? false) === true && $scopeIndex === null) {
                throw new RuntimeException("Le service scoped '{$id}' ne peut être résolu hors d'une requête.");
            }
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
            if (($binding['scoped'] ?? false) === true && $scopeIndex !== null) {
                $this->scopes[$executionId][$scopeIndex][$id] = $instance;
            } elseif (($binding['singleton'] ?? false) === true) {
                $this->instances[$id] = $instance;
            }
            return $instance;
        } finally {
            array_pop($this->resolving[$executionId]);
            if ($this->resolving[$executionId] === []) {
                unset($this->resolving[$executionId]);
            }
        }
    }

    private function executionId(): string
    {
        $fiber = \Fiber::getCurrent();
        return $fiber === null ? 'main' : 'fiber:' . spl_object_id($fiber);
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
            try {
                $arguments[] = $this->get($type->getName());
            } catch (RuntimeException $error) {
                if (!$type->allowsNull() || !$parameter->isDefaultValueAvailable()) {
                    throw $error;
                }
                $arguments[] = $parameter->getDefaultValue();
            }
        }

        return $reflection->newInstanceArgs($arguments);
    }
}
