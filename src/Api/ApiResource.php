<?php

declare(strict_types=1);

namespace PHPAML\Api;

abstract class ApiResource
{
    /** @param array<string,mixed>|object $resource */
    public function __construct(protected array|object $resource) {}

    /** @return array<string,mixed> */
    abstract protected function fields(): array;

    /** @return array<string,ApiResource|list<ApiResource>|null> */
    protected function relations(): array { return []; }

    /** @param list<string> $include @return array<string,mixed> */
    final public function resolve(array $include = []): array
    {
        $result = $this->fields();
        $relations = $this->relations();
        foreach ($include as $name) {
            if (!array_key_exists($name, $relations)) continue;
            $related = $relations[$name];
            $result[$name] = is_array($related)
                ? array_map(static fn (ApiResource $item): array => $item->resolve(), $related)
                : ($related?->resolve());
        }
        return $result;
    }

    /** @param iterable<array<string,mixed>|object> $items @return list<array<string,mixed>> */
    final public static function collection(iterable $items, array $include = []): array
    {
        $result = [];
        foreach ($items as $item) $result[] = (new static($item))->resolve($include);
        return $result;
    }

    protected function value(string $key, mixed $default = null): mixed
    {
        return is_array($this->resource) ? ($this->resource[$key] ?? $default) : ($this->resource->{$key} ?? $default);
    }
}
