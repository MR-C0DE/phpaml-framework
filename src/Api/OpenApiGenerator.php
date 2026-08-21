<?php

declare(strict_types=1);

namespace PHPAML\Api;

final class OpenApiGenerator
{
    public function __construct(private string $title = 'PHPAML API', private string $version = '1.0.0')
    {
    }

    /** @param list<array<string, mixed>>|array<string, mixed> $routes @return array<string, mixed> */
    public function generate(array $routes, string $serverUrl = '/'): array
    {
        $paths = [];
        foreach ($this->normalize($routes) as $route) {
            $method = strtolower((string) $route['method']);
            if (!in_array($method, ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'], true)) {
                continue;
            }
            $path = (string) $route['path'];
            preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $path, $matches);
            $parameters = array_map(static fn (string $name): array => [
                'name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'],
            ], $matches[1] ?? []);
            $operation = [
                'operationId' => $route['name'] ?? $this->operationId($method, $path),
                'responses' => ['200' => ['description' => 'Réponse réussie']],
            ];
            if ($parameters !== []) {
                $operation['parameters'] = $parameters;
            }
            if (in_array($method, ['post', 'put', 'patch'], true)) {
                $operation['requestBody'] = [
                    'required' => true,
                    'content' => ['application/json' => ['schema' => ['type' => 'object']]],
                ];
            }
            $paths[$path][$method] = $operation;
        }
        ksort($paths);
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => $this->title, 'version' => $this->version],
            'servers' => [['url' => $serverUrl]],
            'paths' => $paths,
        ];
    }

    /** @param list<array<string, mixed>>|array<string, mixed> $routes @return list<array<string, mixed>> */
    private function normalize(array $routes): array
    {
        if (array_is_list($routes)) {
            return $routes;
        }
        $normalized = [];
        foreach ($routes as $definition => $config) {
            [$method, $path] = array_pad(explode(' ', (string) $definition, 2), 2, '');
            if ($method !== '' && $path !== '') {
                $normalized[] = ['method' => $method, 'path' => $path, 'name' => is_array($config) ? ($config['name'] ?? null) : null];
            }
        }
        return $normalized;
    }

    private function operationId(string $method, string $path): string
    {
        $name = trim((string) preg_replace('/[^A-Za-z0-9]+/', ' ', $path));
        return $method . str_replace(' ', '', ucwords($name));
    }
}
