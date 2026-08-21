<?php

declare(strict_types=1);

namespace PHPAML\Routing;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

abstract class Route
{
    protected string $prefix = '';

    /** @var array<string, array{0: class-string, 1: string}> */
    private array $definitions = [];

    abstract protected function routes(): void;

    /** @return array<string, array{0: class-string, 1: string}> */
    final public function definitions(): array
    {
        $this->definitions = [];
        $this->routes();

        return $this->definitions;
    }

    /** @param array{0: class-string, 1: string} $handler */
    final protected function get(string $path, array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    /** @param array{0: class-string, 1: string} $handler */
    final protected function post(string $path, array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    /** @param array{0: class-string, 1: string} $handler */
    final protected function put(string $path, array $handler): void
    {
        $this->add('PUT', $path, $handler);
    }

    /** @param array{0: class-string, 1: string} $handler */
    final protected function patch(string $path, array $handler): void
    {
        $this->add('PATCH', $path, $handler);
    }

    /** @param array{0: class-string, 1: string} $handler */
    final protected function delete(string $path, array $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    /** @param array{0: class-string, 1: string} $handler */
    final protected function options(string $path, array $handler): void
    {
        $this->add('OPTIONS', $path, $handler);
    }

    /** @param class-string $controller */
    final protected function apiResource(string $path, string $controller): void
    {
        $path = '/' . trim($path, '/');
        $this->get($path, [$controller, 'index']);
        $this->get($path . '/{id}', [$controller, 'show']);
        $this->post($path, [$controller, 'store']);
        $this->put($path . '/{id}', [$controller, 'update']);
        $this->patch($path . '/{id}', [$controller, 'update']);
        $this->delete($path . '/{id}', [$controller, 'destroy']);
    }

    /**
     * Discover every concrete Route class declared by PHP files in a directory.
     *
     * @return array<string, array{0: class-string, 1: string}>
     */
    final public static function discover(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new RuntimeException("Le dossier de routes est introuvable : '{$directory}'.");
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        foreach ($files as $file) {
            require_once $file;
        }

        $definitions = [];
        $normalizedDirectory = rtrim(str_replace('\\', '/', (string) realpath($directory)), '/') . '/';
        foreach (get_declared_classes() as $class) {
            if (!is_subclass_of($class, self::class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            $source = $reflection->getFileName();
            if ($reflection->isAbstract()
                || !is_string($source)
                || !str_starts_with(str_replace('\\', '/', (string) realpath($source)), $normalizedDirectory)) {
                continue;
            }
            /** @var self $route */
            $route = $reflection->newInstance();
            foreach ($route->definitions() as $definition => $handler) {
                if (isset($definitions[$definition])) {
                    throw new RuntimeException("Route déclarée plusieurs fois : '{$definition}'.");
                }
                $definitions[$definition] = $handler;
            }
        }

        return $definitions;
    }

    /** @param array{0: class-string, 1: string} $handler */
    private function add(string $method, string $path, array $handler): void
    {
        $fullPath = '/' . trim($this->prefix . '/' . ltrim($path, '/'), '/');
        $fullPath = $fullPath === '/' ? '/' : rtrim($fullPath, '/');
        $definition = strtoupper($method) . ' ' . $fullPath;
        if (isset($this->definitions[$definition])) {
            throw new RuntimeException("Route déclarée plusieurs fois : '{$definition}'.");
        }
        $this->definitions[$definition] = $handler;
    }
}
