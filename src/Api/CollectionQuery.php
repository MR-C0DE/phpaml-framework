<?php

declare(strict_types=1);

namespace PHPAML\Api;

use InvalidArgumentException;

/** Parse uniquement les champs explicitement autorisés; aucune entrée cliente ne devient un identifiant SQL. */
final class CollectionQuery
{
    /** @param list<string> $filterable @param list<string> $sortable @param list<string> $searchable */
    public function __construct(
        private array $filterable,
        private array $sortable,
        private array $searchable = [],
        private int $maximumPerPage = 100,
    ) {}

    /** @param array<string,mixed> $query @return array{page:int,per_page:int,filters:array<string,mixed>,sort:list<array{field:string,direction:string}>,search:?string,searchable:list<string>} */
    public function parse(array $query): array
    {
        $filters = [];
        foreach ((array) ($query['filter'] ?? []) as $field => $value) {
            $this->allowed((string) $field, $this->filterable, 'filtre');
            $filters[(string) $field] = $value;
        }
        $sort = [];
        foreach (array_filter(explode(',', (string) ($query['sort'] ?? ''))) as $item) {
            $direction = str_starts_with($item, '-') ? 'desc' : 'asc';
            $field = ltrim($item, '+-');
            $this->allowed($field, $this->sortable, 'tri');
            $sort[] = ['field' => $field, 'direction' => $direction];
        }
        $search = trim((string) ($query['search'] ?? ''));
        return [
            'page' => max(1, (int) ($query['page'] ?? 1)),
            'per_page' => min($this->maximumPerPage, max(1, (int) ($query['per_page'] ?? 20))),
            'filters' => $filters,
            'sort' => $sort,
            'search' => $search === '' ? null : mb_substr($search, 0, 200),
            'searchable' => $this->searchable,
        ];
    }

    /** @param list<string> $allowed */
    private function allowed(string $field, array $allowed, string $kind): void
    {
        if (!in_array($field, $allowed, true)) throw new InvalidArgumentException("Champ de {$kind} non autorisé : '{$field}'.");
    }
}
