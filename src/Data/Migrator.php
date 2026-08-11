<?php

declare(strict_types=1);

namespace PHPAML\Data;

use RuntimeException;

final class Migrator
{
    private const LOCK_FILE = '.aml-migrations.lock';

    public function __construct(private Connection $connection, private string $directory)
    {
    }

    /** @return list<string> */
    public function migrate(): array
    {
        return $this->withLock(function (): array {
            return $this->runPending();
        });
    }

    /** @return list<string> */
    private function runPending(): array
    {
        $pdo = $this->connection->pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS aml_migrations (migration VARCHAR(255) PRIMARY KEY, executed_at VARCHAR(30) NOT NULL)');
        $executed = $pdo->query('SELECT migration FROM aml_migrations')->fetchAll(\PDO::FETCH_COLUMN);
        $completed = [];
        $files = glob($this->directory . '/*.php') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
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
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            }
        }
        return $completed;
    }

    /** Roll back the latest migration batch, one migration by default. @return list<string> */
    public function rollback(int $steps = 1): array
    {
        if ($steps < 1) {
            throw new RuntimeException('Le nombre de migrations à annuler doit être positif.');
        }
        return $this->withLock(function () use ($steps): array {
            $pdo = $this->connection->pdo();
            $pdo->exec('CREATE TABLE IF NOT EXISTS aml_migrations (migration VARCHAR(255) PRIMARY KEY, executed_at VARCHAR(30) NOT NULL)');
            $statement = $pdo->prepare('SELECT migration FROM aml_migrations ORDER BY executed_at DESC, migration DESC LIMIT ?');
            $statement->bindValue(1, $steps, \PDO::PARAM_INT);
            $statement->execute();
            $rolledBack = [];
            foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $name) {
                $file = $this->directory . '/' . basename((string) $name);
                if (!is_file($file)) {
                    throw new RuntimeException("Le fichier de migration '{$name}' est introuvable.");
                }
                $migration = require $file;
                if (!$migration instanceof Migration) {
                    throw new RuntimeException("La migration '{$name}' est invalide.");
                }
                $pdo->beginTransaction();
                try {
                    $migration->down($this->connection);
                    $delete = $pdo->prepare('DELETE FROM aml_migrations WHERE migration = ?');
                    $delete->execute([$name]);
                    $pdo->commit();
                    $rolledBack[] = (string) $name;
                } catch (\Throwable $error) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $error;
                }
            }
            return $rolledBack;
        });
    }

    /** @template T @param callable(): T $operation @return T */
    private function withLock(callable $operation): mixed
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Impossible de créer le dossier des migrations.');
        }
        $handle = fopen($this->directory . '/' . self::LOCK_FILE, 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Une autre opération de migration est déjà en cours.');
        }
        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
