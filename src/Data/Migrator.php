<?php

declare(strict_types=1);

namespace PHPAML\Data;

use RuntimeException;

final class Migrator
{
    public function __construct(private Connection $connection, private string $directory)
    {
    }

    /** @return list<string> */
    public function migrate(): array
    {
        $pdo = $this->connection->pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS aml_migrations (migration VARCHAR(255) PRIMARY KEY, executed_at VARCHAR(30) NOT NULL)');
        $executed = $pdo->query('SELECT migration FROM aml_migrations')->fetchAll(\PDO::FETCH_COLUMN);
        $completed = [];
        foreach (glob($this->directory . '/*.php') ?: [] as $file) {
            $name = basename($file);
            if (in_array($name, $executed, true)) {
                continue;
            }
            $migration = require $file;
            if (!$migration instanceof Migration) {
                throw new RuntimeException("La migration '{$name}' est invalide.");
            }
            $pdo->beginTransaction();
            try {
                $migration->up($this->connection);
                $statement = $pdo->prepare('INSERT INTO aml_migrations (migration, executed_at) VALUES (?, ?)');
                $statement->execute([$name, date(DATE_ATOM)]);
                $pdo->commit();
                $completed[] = $name;
            } catch (\Throwable $error) {
                $pdo->rollBack();
                throw $error;
            }
        }
        return $completed;
    }
}
