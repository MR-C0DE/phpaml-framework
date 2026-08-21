<?php

declare(strict_types=1);

namespace PHPAML\Api;

use PDO;
use PDOException;
use PHPAML\Data\Connection;

final class AuthManager
{
    public function __construct(private Connection $connection, private TokenManager $tokens)
    {
        $this->migrate();
    }

    public function migrate(): void
    {
        $this->connection->pdo()->exec('CREATE TABLE IF NOT EXISTS api_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(150) NOT NULL, email VARCHAR(254) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, created_at VARCHAR(32) NOT NULL)');
    }

    /** @return array{id:int,name:string,email:string,created_at:string} */
    public function register(string $name, string $email, string $password): array
    {
        $name = trim($name); $email = strtolower(trim($email));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            throw new AuthException('INVALID_REGISTRATION', 'Nom, courriel valide et mot de passe de 8 caractères minimum requis.', 422);
        }
        $statement = $this->connection->pdo()->prepare('INSERT INTO api_users (name, email, password_hash, created_at) VALUES (:name, :email, :password, :created)');
        try {
            $statement->execute(['name' => $name, 'email' => $email, 'password' => password_hash($password, PASSWORD_DEFAULT), 'created' => gmdate(DATE_ATOM)]);
        } catch (PDOException $error) {
            if (str_contains(strtolower($error->getMessage()), 'unique')) { throw new AuthException('EMAIL_TAKEN', 'Ce courriel est déjà utilisé.', 422); }
            throw $error;
        }
        return $this->user((string) $this->connection->pdo()->lastInsertId()) ?? throw new \RuntimeException('Utilisateur introuvable après création.');
    }

    /** @param list<string> $abilities @return array{token:string,user:array<string,mixed>} */
    public function login(string $email, string $password, string $device = 'api', array $abilities = ['*']): array
    {
        $statement = $this->connection->pdo()->prepare('SELECT * FROM api_users WHERE email = :email LIMIT 1');
        $statement->execute(['email' => strtolower(trim($email))]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !password_verify($password, (string) $row['password_hash'])) {
            password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
            throw new AuthException('INVALID_CREDENTIALS', 'Identifiants invalides.', 401);
        }
        if (password_needs_rehash((string) $row['password_hash'], PASSWORD_DEFAULT)) {
            $update = $this->connection->pdo()->prepare('UPDATE api_users SET password_hash = :hash WHERE id = :id');
            $update->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $row['id']]);
        }
        return ['token' => $this->tokens->issue((string) $row['id'], $device, $abilities), 'user' => $this->publicUser($row)];
    }

    /** @return array{id:int,name:string,email:string,created_at:string}|null */
    public function user(string|int $id): ?array
    {
        $statement = $this->connection->pdo()->prepare('SELECT * FROM api_users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->publicUser($row) : null;
    }

    /** @param array<string,mixed> $row @return array{id:int,name:string,email:string,created_at:string} */
    private function publicUser(array $row): array
    {
        return ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'email' => (string) $row['email'], 'created_at' => (string) $row['created_at']];
    }
}
