<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Autoloader.php';
PHPAML\Autoloader::register(['PHPAML\\' => dirname(__DIR__) . '/src']);

use PHPAML\Container;
use PHPAML\Http\Request;
use PHPAML\Http\Response;
use PHPAML\Middleware\MiddlewareInterface;
use PHPAML\Routing\Router;
use PHPAML\Data\Connection;
use PHPAML\Data\QueryBuilder;
use PHPAML\Data\Migrator;
use PHPAML\Logging\Logger;
use PHPAML\Middleware\RateLimitMiddleware;

final class SecurityTestController { public function show(Request $request): Response { return Response::json(['id' => $request->attribute('id')]); } }
final class SecurityTestMiddleware implements MiddlewareInterface { public function process(Request $request, Closure $next): Response { return $next($request); } }

$tests = [];
$test = static function (string $name, Closure $case) use (&$tests): void { $tests[$name] = $case; };
$expect = static function (bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } };
$throws = static function (Closure $case) use ($expect): void { try { $case(); } catch (Throwable) { return; } $expect(false, 'Une exception était attendue.'); };

$test('les parties statiques des routes sont échappées', function () use ($expect): void {
    $router = new Router(new Container());
    $router->add('GET', '/v1.0/{id}', [SecurityTestController::class, 'show']);
    $expect($router->dispatch(new Request('GET', '/v1.0/7'))->status() === 200, 'La route exacte doit correspondre.');
    $expect($router->dispatch(new Request('GET', '/v1X0/7'))->status() === 404, 'Le point statique ne doit pas agir comme une expression régulière.');
});

$test('les définitions de route invalides sont refusées', function () use ($throws): void {
    $router = new Router(new Container());
    $throws(fn() => $router->add('TRACE', '/x', [SecurityTestController::class, 'show']));
    $throws(fn() => $router->add('GET', '/x', [SecurityTestController::class, 'missing']));
    $throws(fn() => $router->add('GET', '/x', [SecurityTestController::class, 'show'], [stdClass::class]));
});

$test('JSON non encodable et redirections externes sont refusés', function () use ($throws, $expect): void {
    $resource = fopen('php://memory', 'r');
    $throws(fn() => Response::json(['resource' => $resource]));
    fclose($resource);
    $throws(fn() => Response::redirect('https://example.com'));
    $throws(fn() => Response::redirect('//example.com'));
    $expect(Response::redirect('/login', 303)->headers()['Location'] === '/login', 'La redirection interne doit fonctionner.');
});

$test('les injections dans les en-têtes sont refusées', function () use ($throws): void {
    $throws(fn() => Response::html('ok')->withHeader('X-Test', "ok\r\nInjected: yes"));
});

$test('le QueryBuilder refuse les identifiants injectés et les insertions vides', function () use ($throws, $expect): void {
    $connection = new Connection('sqlite::memory:');
    $connection->pdo()->exec('CREATE TABLE records (id INTEGER PRIMARY KEY, count INTEGER, active INTEGER, label TEXT, optional TEXT)');
    $query = new QueryBuilder($connection);
    $throws(fn () => $query->all('records; DROP TABLE records'));
    $throws(fn () => $query->insert('records', []));
    $id = $query->insert('records', ['count' => 7, 'active' => true, 'label' => 'test', 'optional' => null]);
    $row = $query->all('records')[0];
    $expect($id === 1 && $row['count'] === 7 && $row['active'] === 1 && $row['optional'] === null, 'Les types PDO doivent être conservés.');
});

$test('le journal structuré masque les secrets imbriqués', function () use ($expect): void {
    $file = tempnam(sys_get_temp_dir(), 'phpaml-log-');
    if ($file === false) {
        throw new RuntimeException('Impossible de créer le journal temporaire.');
    }
    (new Logger($file))->log('warning', 'Connexion refusée', [
        'password' => 'secret-value',
        'request_id' => 'abc123',
        'nested' => ['token' => 'private-token'],
    ]);
    $content = (string) file_get_contents($file);
    unlink($file);
    $record = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    $expect($record['level'] === 'warning' && $record['context']['request_id'] === 'abc123', 'Le journal doit être structuré.');
    $expect(!str_contains($content, 'secret-value') && !str_contains($content, 'private-token'), 'Les secrets doivent être masqués.');
});

$test('les migrations sont ordonnées et peuvent être annulées', function () use ($expect): void {
    $directory = sys_get_temp_dir() . '/phpaml-migrations-' . bin2hex(random_bytes(6));
    mkdir($directory, 0755, true);
    $migrationSource = static fn (string $table): string => "<?php\nreturn new class extends \\PHPAML\\Data\\Migration { public function up(\\PHPAML\\Data\\Connection \$connection): void { \$connection->pdo()->exec('CREATE TABLE {$table} (id INTEGER)'); } public function down(\\PHPAML\\Data\\Connection \$connection): void { \$connection->pdo()->exec('DROP TABLE {$table}'); } };\n";
    file_put_contents($directory . '/002_second.php', $migrationSource('second_table'));
    file_put_contents($directory . '/001_first.php', $migrationSource('first_table'));
    $connection = new Connection('sqlite::memory:');
    $migrator = new Migrator($connection, $directory);
    $expect($migrator->migrate() === ['001_first.php', '002_second.php'], 'Les migrations doivent suivre un ordre déterministe.');
    $expect($migrator->rollback() === ['002_second.php'], 'La dernière migration doit être annulée en premier.');
    $tables = $connection->pdo()->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    $expect(in_array('first_table', $tables, true) && !in_array('second_table', $tables, true), 'Le retour arrière doit exécuter down().');
    foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
    unlink($directory . '/.aml-migrations.lock');
    rmdir($directory);
});

$test('la limitation bloque les actions sensibles après le seuil', function () use ($expect): void {
    $directory = sys_get_temp_dir() . '/phpaml-rate-' . bin2hex(random_bytes(6));
    $middleware = new RateLimitMiddleware($directory, 2, 60);
    $request = new Request('POST', '/login', [], [], ['REMOTE_ADDR' => '127.0.0.1']);
    $next = static fn (): Response => Response::json(['ok' => true]);
    $expect($middleware->process($request, $next)->status() === 200, 'La première tentative doit passer.');
    $expect($middleware->process($request, $next)->status() === 200, 'La deuxième tentative doit passer.');
    $blocked = $middleware->process($request, $next);
    $expect($blocked->status() === 429 && isset($blocked->headers()['Retry-After']), 'La tentative suivante doit être temporairement bloquée.');
    foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
    rmdir($directory);
});

$failed = 0;
foreach ($tests as $name => $case) {
    try { $case(); echo "✓ {$name}\n"; } catch (Throwable $error) { fwrite(STDERR, "✗ {$name}: {$error->getMessage()}\n"); $failed++; }
}
exit($failed === 0 ? 0 : 1);
