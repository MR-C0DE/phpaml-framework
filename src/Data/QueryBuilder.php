<?php

declare(strict_types=1);

namespace PHPAML\Data;

use InvalidArgumentException;
use PDO;

final class QueryBuilder
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(string $table): array
    {
        $table = $this->identifier($table);
        return $this->connection->pdo()->query("SELECT * FROM {$table}")->fetchAll();
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): int
    {
        if ($data === []) {
            throw new InvalidArgumentException('Une insertion vide est interdite.');
        }
        $table = $this->identifier($table);
        $columns = array_map($this->identifier(...), array_keys($data));
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
        $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $statement = $this->connection->pdo()->prepare($sql);
        foreach (array_values($data) as $index => $value) {
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue($placeholders[$index], $value, $type);
        }
        $statement->execute();
        return (int) $this->connection->pdo()->lastInsertId();
    }

    private function identifier(string $value): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $value)) {
            throw new InvalidArgumentException("Identifiant SQL invalide : '{$value}'.");
        }
        return $value;
    }
}
