<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Autoloader.php';
PHPAML\Autoloader::register(['PHPAML\\' => dirname(__DIR__) . '/src']);

use PHPAML\Container;
use PHPAML\Http\Request;
use PHPAML\Http\Response;
use PHPAML\Middleware\MiddlewareInterface;
use PHPAML\Routing\Router;

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

$failed = 0;
foreach ($tests as $name => $case) {
    try { $case(); echo "✓ {$name}\n"; } catch (Throwable $error) { fwrite(STDERR, "✗ {$name}: {$error->getMessage()}\n"); $failed++; }
}
exit($failed === 0 ? 0 : 1);
