<?php

declare(strict_types=1);

namespace PHPAML\Config;

use PHPAML\Middleware\SecurityHeadersMiddleware;
use RuntimeException;

final class ApplicationConfig
{
    /** @return array<string, mixed> */
    public static function load(string $projectRoot): array
    {
        $projectRoot = rtrim($projectRoot, '/\\');
        Env::load($projectRoot . '/.env');
        $manifestPath = $projectRoot . '/phpaml.json';
        $manifest = json_decode((string) @file_get_contents($manifestPath), true);
        if (!is_array($manifest)) {
            throw new RuntimeException("Le fichier phpaml.json est introuvable ou invalide.");
        }

        // Projects created before declarative configuration keep working until
        // they are migrated. New projects declare an `application` object.
        if (!isset($manifest['application'])) {
            foreach ([$projectRoot . '/config/app.php', $projectRoot . '/configs/app.php'] as $legacyPath) {
                if (is_file($legacyPath)) {
                    $legacy = require $legacyPath;
                    if (!is_array($legacy)) {
                        throw new RuntimeException("La configuration historique '{$legacyPath}' est invalide.");
                    }
                    $legacy['project_root'] ??= $projectRoot;
                    self::writeCache($projectRoot, $legacy);
                    return $legacy;
                }
            }
        }

        $application = is_array($manifest['application'] ?? null) ? $manifest['application'] : [];
        $database = is_array($manifest['database'] ?? null) ? $manifest['database'] : [];
        $api = is_array($manifest['api'] ?? null) ? $manifest['api'] : [];
        $data = is_array($manifest['data'] ?? null) ? $manifest['data'] : [];
        $session = is_array($application['session'] ?? null) ? $application['session'] : [];
        $rateLimit = is_array($application['rate_limit'] ?? null) ? $application['rate_limit'] : [];
        $type = (string) ($application['type'] ?? $manifest['type'] ?? 'classic');
        $views = (string) ($application['views'] ?? 'app/views');
        $middlewares = is_array($application['middlewares'] ?? null)
            ? array_values(array_filter($application['middlewares'], 'is_string'))
            : [SecurityHeadersMiddleware::class];
        $dsn = (string) Env::get('DATABASE_DSN', $database['dsn'] ?? 'sqlite:runtime/storage/database.sqlite');
        if (str_starts_with($dsn, 'sqlite:') && !str_starts_with($dsn, 'sqlite::memory:')) {
            $sqlitePath = substr($dsn, strlen('sqlite:'));
            $dsn = 'sqlite:' . self::absolute($projectRoot, $sqlitePath);
        }

        $config = [
            'project_root' => $projectRoot,
            'name' => (string) ($manifest['name'] ?? basename($projectRoot)),
            'type' => $type,
            'debug' => Env::bool('APP_DEBUG', (bool) ($application['debug'] ?? false)),
            'session' => [
                'lifetime' => (int) ($session['lifetime'] ?? 7200),
                'same_site' => (string) ($session['same_site'] ?? 'Lax'),
            ],
            'log_path' => self::absolute($projectRoot, (string) ($application['log'] ?? 'runtime/storage/logs/application.log')),
            'rate_limit' => [
                'enabled' => (bool) ($rateLimit['enabled'] ?? true),
                'storage_path' => self::absolute($projectRoot, (string) ($rateLimit['storage'] ?? 'runtime/storage/rate-limits')),
                'limit' => (int) ($rateLimit['limit'] ?? 60),
                'window' => (int) ($rateLimit['window'] ?? 60),
                'methods' => is_array($rateLimit['methods'] ?? null)
                    ? array_values(array_filter($rateLimit['methods'], 'is_string'))
                    : ['POST', 'PUT', 'PATCH', 'DELETE'],
            ],
            'database' => [
                'dsn' => $dsn,
                'username' => (string) Env::get('DATABASE_USER', $database['username'] ?? 'root'),
                'password' => (string) Env::get('DATABASE_PASSWORD', $database['password'] ?? ''),
            ],
            'routes' => [],
            'middlewares' => $middlewares,
        ];
        if ($api !== []) {
            $cors = is_array($api['cors'] ?? null) ? $api['cors'] : [];
            $originDefault = is_array($cors['origins'] ?? null) ? implode(',', $cors['origins']) : 'http://localhost:5173';
            $cors['origins'] = array_values(array_filter(array_map('trim', explode(',', (string) Env::get('API_CORS_ORIGINS', $originDefault)))));
            $api['cors'] = $cors;
            $tokens = is_array($api['tokens'] ?? null) ? $api['tokens'] : [];
            $production = is_array($api['production'] ?? null) ? $api['production'] : [];
            if (isset($tokens['storage_path'])) {
                $tokens['storage_path'] = self::absolute($projectRoot, (string) $tokens['storage_path']);
            }
            if (isset($production['idempotency_path'])) {
                $production['idempotency_path'] = self::absolute($projectRoot, (string) $production['idempotency_path']);
            }
            $api['tokens'] = $tokens;
            $api['production'] = $production;
            $config['api'] = $api;
        }
        if ($data !== []) {
            $data['default'] = (string) Env::get('DATA_CONNECTION', $data['default'] ?? 'main');
            $connections = is_array($data['connections'] ?? null) ? $data['connections'] : [];
            $main = is_array($connections['main'] ?? null) ? $connections['main'] : [];
            $main['driver'] = (string) Env::get('DATA_DRIVER', $main['driver'] ?? 'sqlite');
            foreach (['dsn' => 'DATA_DSN', 'database' => 'DATA_DATABASE', 'host' => 'DATA_HOST', 'port' => 'DATA_PORT', 'username' => 'DATA_USERNAME', 'password' => 'DATA_PASSWORD', 'uri' => 'DATA_URI'] as $key => $environment) {
                $main[$key] = Env::get($environment, $main[$key] ?? null);
            }
            if (($main['driver'] ?? null) === 'sqlite' && is_string($main['database'] ?? null)) {
                $main['database'] = self::absolute($projectRoot, $main['database']);
            }
            $connections['main'] = $main;
            $data['connections'] = $connections;
            $data['migrations_path'] = self::absolute($projectRoot, (string) ($data['migrations_path'] ?? 'runtime/database/migrations'));
            $data['models_path'] = self::absolute($projectRoot, (string) ($data['models_path'] ?? 'src/models'));
            $config['data'] = $data;
        }
        if ($type === 'classic' && is_dir(self::absolute($projectRoot, $views))) {
            $config['views_path'] = self::absolute($projectRoot, $views);
        }

        self::writeCache($projectRoot, $config);
        return $config;
    }

    private static function absolute(string $root, string $path): string
    {
        if ($path === '') return $root;
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) return $path;
        return $root . '/' . ltrim($path, '/\\');
    }

    /** @param array<string, mixed> $config */
    private static function writeCache(string $root, array $config): void
    {
        $directory = $root . '/runtime/config';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Impossible de créer runtime/config.');
        }
        $content = "<?php\n\n// Generated by PHPAML. Do not edit.\nreturn " . var_export($config, true) . ";\n";
        if (is_file($directory . '/app.php') && file_get_contents($directory . '/app.php') === $content) {
            return;
        }
        if (file_put_contents($directory . '/app.php', $content, LOCK_EX) === false) {
            throw new RuntimeException('Impossible de générer runtime/config/app.php.');
        }
        @chmod($directory . '/app.php', 0600);
    }
}
