<?php

declare(strict_types=1);

namespace PHPAML\Data;

use PDO;

final class Connection
{
    private ?PDO $pdo = null;

    public function __construct(
        private string $dsn,
        private ?string $username = null,
        private ?string $password = null
    ) {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            if (str_starts_with($this->dsn, 'sqlite:')) {
                $path = substr($this->dsn, 7);
                if ($path !== ':memory:' && !is_dir(dirname($path))) {
                    mkdir(dirname($path), 0755, true);
                }
            }
            $this->pdo = new PDO($this->dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
        return $this->pdo;
    }
}
