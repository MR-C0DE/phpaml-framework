<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Autoloader.php';
PHPAML\Autoloader::register(['PHPAML\\' => dirname(__DIR__) . '/src']);

use PHPAML\Container;
use PHPAML\Config\ApplicationConfig;
use PHPAML\Config\Env;
use PHPAML\Http\Request;
use PHPAML\Http\Response;
use PHPAML\Middleware\MiddlewareInterface;
use PHPAML\Routing\Router;
use PHPAML\Routing\Route;
use PHPAML\Data\Connection;
use PHPAML\Data\QueryBuilder;
use PHPAML\Data\Migrator;
use PHPAML\Logging\Logger;
use PHPAML\Middleware\RateLimitMiddleware;
use PHPAML\Middleware\CsrfMiddleware;
use PHPAML\Middleware\SecurityHeadersMiddleware;
use PHPAML\Middleware\LocaleMiddleware;
use PHPAML\Security\CspNonce;
use PHPAML\Session\Session;
use PHPAML\Session\SessionStoreInterface;
use PHPAML\Session\RedisSessionStore;
use PHPAML\WebApplication;
use PHPAML\Api\ApiResponse;
use PHPAML\Api\TokenManager;
use PHPAML\Middleware\ApiAuthMiddleware;
use PHPAML\Api\ApiRequest;
use PHPAML\Api\FileIdempotencyStore;
use PHPAML\Api\OpenApiGenerator;
use PHPAML\Api\TypeScriptClientGenerator;
use PHPAML\Middleware\ApiVersionMiddleware;
use PHPAML\Middleware\HttpCacheMiddleware;
use PHPAML\Middleware\IdempotencyMiddleware;
use PHPAML\Middleware\RequestIdMiddleware;
use PHPAML\Api\CollectionQuery;
use PHPAML\Api\ApiResource;
use PHPAML\Validation\Validator;
use PHPAML\Http\UploadedFile;
use PHPAML\Api\AuthManager;
use PHPAML\Api\AuthController;
use PHPAML\Api\AuthException;
use PHPAML\Middleware\AbilityMiddleware;
use PHPAML\Middleware\RedisRateLimitMiddleware;

if (($argv[1] ?? '') === 'token-worker') {
    $manager = new TokenManager((string) ($argv[2] ?? ''), 300);
    $owner = (string) ($argv[3] ?? 'worker');
    $count = max(1, (int) ($argv[4] ?? 1));
    for ($index = 0; $index < $count; $index++) { $manager->issue($owner, 'concurrency'); }
    exit(0);
}

if (($argv[1] ?? '') === 'rotate-worker') {
    $manager = new TokenManager((string) ($argv[2] ?? ''), 300);
    $replacement = $manager->rotate((string) ($argv[3] ?? ''));
    if ($replacement !== null) { fwrite(STDOUT, $replacement); }
    exit(0);
}

final class SecurityTestController { public function show(Request $request): Response { return Response::json(['id' => $request->attribute('id')]); } }
final class SecurityTestMiddleware implements MiddlewareInterface { public function process(Request $request, Closure $next): Response { return $next($request)->withHeader('X-Test-Pipeline', 'active'); } }
final class MemorySessionStore implements SessionStoreInterface
{
    /** @var array<string, mixed> */
    private array $values = [];
    public int $closeCount = 0;
    public function set(string $key, mixed $value): void { $this->values[$key] = $value; }
    public function get(string $key, mixed $default = null): mixed { return $this->values[$key] ?? $default; }
    public function remove(string $key): void { unset($this->values[$key]); }
    public function regenerate(): void {}
    public function close(): void { $this->closeCount++; }
}
final class MemoryRedisClient
{
    /** @var array<string, array<string, string>> */
    public array $hashes = [];
    /** @var array<string, int> */
    public array $expirations = [];
    public function hSet(string $key, string $field, string $value): int { $this->hashes[$key][$field] = $value; return 1; }
    public function hGet(string $key, string $field): string|false { return $this->hashes[$key][$field] ?? false; }
    public function hDel(string $key, string $field): int { unset($this->hashes[$key][$field]); return 1; }
    public function expire(string $key, int $ttl): bool { $this->expirations[$key] = $ttl; return true; }
    public function exists(string $key): int { return isset($this->hashes[$key]) ? 1 : 0; }
    public function rename(string $from, string $to): bool { $this->hashes[$to] = $this->hashes[$from]; unset($this->hashes[$from]); return true; }
}
final class ResourceTestController
{
    public function index(Request $request): Response { return Response::json([]); }
    public function show(Request $request): Response { return Response::json([]); }
    public function store(Request $request): Response { return Response::json([], 201); }
    public function update(Request $request): Response { return Response::json([]); }
    public function destroy(Request $request): Response { return Response::json(null, 204); }
}
final class ResourceTestRoute extends Route
{
    protected string $prefix = '/api/v1';
    protected function routes(): void
    {
        $this->apiResource('/movies', ResourceTestController::class);
    }
}

$tests = [];
$test = static function (string $name, Closure $case) use (&$tests): void { $tests[$name] = $case; };
$expect = static function (bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } };
$throws = static function (Closure $case) use ($expect): void { try { $case(); } catch (Throwable) { return; } $expect(false, 'Une exception était attendue.'); };

$test('phpaml.json et .env génèrent la configuration runtime', function () use ($expect): void {
    $root = sys_get_temp_dir() . '/phpaml-config-' . bin2hex(random_bytes(6));
    mkdir($root . '/app/views', 0755, true);
    file_put_contents($root . '/phpaml.json', json_encode([
        'name' => 'configuration-test',
        'application' => ['type' => 'classic', 'debug' => false, 'rate_limit' => ['limit' => 25]],
        'database' => ['dsn' => 'sqlite::memory:'],
        'api' => ['enabled' => true, 'cors' => ['origins' => ['https://example.test']], 'tokens' => ['storage_path' => 'runtime/storage/tokens.json']],
        'data' => ['default' => 'main', 'connections' => ['main' => ['driver' => 'sqlite', 'database' => 'runtime/storage/data.sqlite']]],
        'i18n' => ['enabled' => true, 'default' => 'en', 'fallback' => 'fr', 'supported' => ['en', 'fr'], 'detection' => ['route', 'cookie', 'header']],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($root . '/.env', "APP_DEBUG=true\nDATABASE_USER=demo\n");
    $config = ApplicationConfig::load($root);
    $expect($config['name'] === 'configuration-test' && $config['debug'] === true, '.env doit surcharger phpaml.json.');
    $expect($config['rate_limit']['limit'] === 25 && $config['database']['username'] === 'demo', 'Les réglages déclaratifs doivent être normalisés.');
    $expect($config['api']['tokens']['storage_path'] === $root . '/runtime/storage/tokens.json', 'La configuration API doit être normalisée.');
    $expect($config['data']['connections']['main']['database'] === $root . '/runtime/storage/data.sqlite', 'La configuration Data doit être normalisée.');
    $expect($config['i18n']['directory'] === $root . '/src/locales' && $config['i18n']['supported'] === ['en', 'fr'], 'La configuration i18n doit être normalisée.');
    $expect(is_file($root . '/runtime/config/app.php'), 'Le cache runtime/config/app.php doit être généré.');
    unlink($root . '/runtime/config/app.php'); rmdir($root . '/runtime/config'); rmdir($root . '/runtime');
    unlink($root . '/.env'); unlink($root . '/phpaml.json'); rmdir($root . '/app/views'); rmdir($root . '/app'); rmdir($root);
});

$test('le chargement de deux environnements ne conserve aucune valeur du projet précédent', function () use ($expect): void {
    $root = sys_get_temp_dir() . '/phpaml-env-isolation-' . bin2hex(random_bytes(6));
    mkdir($root, 0700, true);
    file_put_contents($root . '/first.env', "PHPAML_ISOLATED_VALUE=first\n");
    file_put_contents($root . '/second.env', "OTHER_VALUE=second\n");
    Env::load($root . '/first.env');
    $expect(Env::get('PHPAML_ISOLATED_VALUE') === 'first', 'Le premier environnement doit être chargé.');
    Env::load($root . '/second.env');
    $expect(Env::get('PHPAML_ISOLATED_VALUE') === null, 'Une valeur absente du nouveau projet ne doit pas survivre.');
    $expect(Env::get('OTHER_VALUE') === 'second', 'Le second environnement doit être chargé.');
    Env::load($root . '/missing.env');
    $expect(Env::get('OTHER_VALUE') === null, 'Un projet sans .env doit repartir avec un état vide.');
    unlink($root . '/first.env');
    unlink($root . '/second.env');
    rmdir($root);
});

$test("la lecture locale d'un environnement ne modifie pas l'état global historique", function () use ($expect): void {
    $root = sys_get_temp_dir() . '/phpaml-env-snapshot-' . bin2hex(random_bytes(6));
    mkdir($root, 0700, true);
    file_put_contents($root . '/global.env', "SCOPE_VALUE=global\n");
    file_put_contents($root . '/local.env', "SCOPE_VALUE=local\nLOCAL_ONLY=yes\n");
    Env::load($root . '/global.env');
    $snapshot = Env::read($root . '/local.env');
    $expect($snapshot['SCOPE_VALUE'] === 'local' && $snapshot['LOCAL_ONLY'] === 'yes', 'La lecture locale doit retourner son propre instantané.');
    $expect(Env::get('SCOPE_VALUE') === 'global' && Env::get('LOCAL_ONLY') === null, "La lecture locale ne doit pas remplacer l'état de compatibilité global.");
    unlink($root . '/global.env'); unlink($root . '/local.env'); rmdir($root);
});

$test('une classe Route déclare une ressource API avec un préfixe', function () use ($expect): void {
    $definitions = (new ResourceTestRoute())->definitions();
    $expect(count($definitions) === 6, 'apiResource doit déclarer les six opérations REST.');
    $expect(($definitions['GET /api/v1/movies'] ?? null) === [ResourceTestController::class, 'index'], 'La route de collection est incorrecte.');
    $expect(($definitions['DELETE /api/v1/movies/{id}'] ?? null) === [ResourceTestController::class, 'destroy'], 'La route de suppression est incorrecte.');
});

$test('WebApplication découvre automatiquement src/routes', function () use ($expect): void {
    $root = sys_get_temp_dir() . '/phpaml-auto-routes-' . bin2hex(random_bytes(6));
    mkdir($root . '/src/routes', 0755, true);
    $class = 'AutoRoute' . bin2hex(random_bytes(5));
    $source = "<?php\nfinal class {$class} extends \\PHPAML\\Routing\\Route { protected function routes(): void { \$this->get('/auto', [\\SecurityTestController::class, 'show']); } }\n";
    file_put_contents($root . '/src/routes/AutoRoute.php', $source);
    $application = new WebApplication(['project_root' => $root]);
    $response = $application->handle(new Request('GET', '/auto'));
    $expect($response->status() === 200, 'La route placée dans src/routes doit être chargée sans configuration manuelle.');
    unlink($root . '/src/routes/AutoRoute.php');
    rmdir($root . '/src/routes');
    rmdir($root . '/src');
    rmdir($root);
});

$test('les paquets optionnels sont composés par un bootstrapper applicatif', function () use ($expect): void {
    $service = new stdClass();
    $called = false;
    $application = new WebApplication([
        'legacy_data_bootstrap' => false,
        'bootstrappers' => [
            static function (\PHPAML\Container $container, array $config) use ($service, &$called): void {
                $called = ($config['name'] ?? null) === 'modular-test';
                $container->set('optional.service', $service);
            },
            'not-a-callable',
        ],
        'name' => 'modular-test',
    ]);

    $expect($called, 'Le bootstrapper doit recevoir la configuration de l’application.');
    $expect($application->container()->get('optional.service') === $service, 'Le bootstrapper doit pouvoir enregistrer un service.');
});

$test('les services scoped sont isolés et nettoyés après chaque requête', function () use ($expect, $throws): void {
    $application = new WebApplication([]);
    $container = $application->container();
    $container->scoped('request.service', static fn (): object => new stdClass());
    $instances = [];

    foreach (['/first', '/second'] as $path) {
        $request = new Request('GET', $path);
        $application->handle($request, static function (Request $active) use ($container, &$instances, $expect, $request): Response {
            $first = $container->get('request.service');
            $second = $container->get('request.service');
            $expect($first === $second, 'Un service scoped doit être stable pendant une requête.');
            $expect($container->get(Request::class) === $request, 'La requête active doit être injectable dans sa propre portée.');
            $expect($active === $request, 'La destination doit recevoir la même requête que le conteneur.');
            $instances[] = $first;
            return Response::html('ok');
        });
        $expect(!$container->hasActiveScope(), 'La portée doit être fermée après la réponse.');
    }

    $expect($instances[0] !== $instances[1], 'Deux requêtes ne doivent jamais partager un service scoped.');
    $throws(fn (): object => $container->get('request.service'));
    $throws(fn (): object => $container->get(Request::class));
});

$test('la session délègue son stockage et ferme celui-ci avec la portée', function () use ($expect): void {
    $container = new Container();
    $store = new MemorySessionStore();
    $container->scoped(Session::class, static fn (): Session => new Session([], $store));
    $container->beginScope();
    $session = $container->get(Session::class);
    $session->set('user', 42);
    $expect($session->get('user') === 42, 'La session doit déléguer la lecture et l’écriture à son stockage.');
    $container->endScope();
    $expect($store->closeCount === 1, 'Le stockage de session doit être fermé exactement une fois avec la portée.');
});

$test('le stockage Redis conserve des champs atomiques et régénère son identifiant', function () use ($expect): void {
    $redis = new MemoryRedisClient();
    $first = new RedisSessionStore($redis, 'shared-session', 300);
    $second = new RedisSessionStore($redis, 'shared-session', 300);
    $first->set('profile', ['name' => 'Ada']);
    $second->set('flash', 'saved');
    $expect($first->get('flash') === 'saved' && $second->get('profile') === ['name' => 'Ada'], 'Deux requêtes ne doivent pas écraser les champs Redis distincts.');
    $previous = $first->id();
    $first->regenerate();
    $expect($first->id() !== $previous && $first->get('profile') === ['name' => 'Ada'], 'La rotation doit déplacer atomiquement les données vers le nouvel identifiant.');
    $first->remove('flash');
    $expect($first->get('flash') === null, 'La suppression Redis doit être immédiatement visible.');
    $session = new Session([], $first);
    $expect($session->id() === $first->id(), 'La session doit exposer l’identifiant de son stockage distribué.');
});

$test('une portée de requête est nettoyée même lorsque le traitement échoue', function () use ($expect): void {
    $application = new WebApplication([]);
    $response = $application->handle(
        new Request('GET', '/failure'),
        static function (): Response { throw new RuntimeException('failure'); },
    );
    $expect($response->status() === 500, 'Le middleware d’erreur doit convertir l’exception en réponse.');
    $expect(!$application->container()->hasActiveScope(), 'Une réponse en erreur doit fermer la portée active.');
});

$test('les portées imbriquées restaurent le contexte extérieur', function () use ($expect): void {
    $container = new Container();
    $container->scoped('nested.service', static fn (): object => new stdClass());

    $container->beginScope();
    try {
        $outer = $container->get('nested.service');
        $container->beginScope();
        try {
            $inner = $container->get('nested.service');
            $expect($inner !== $outer, 'Une portée imbriquée doit isoler ses propres services.');
        } finally {
            $container->endScope();
        }
        $expect($container->get('nested.service') === $outer, 'Fermer la portée imbriquée doit restaurer le service extérieur.');
    } finally {
        $container->endScope();
    }
});

$test('deux Fibers concurrentes ne partagent ni requête ni service scoped', function () use ($expect): void {
    $container = new Container();
    $container->scoped('fiber.service', static fn (): object => new stdClass());
    $run = static function (string $path) use ($container): array {
        $container->beginScope();
        try {
            $request = new Request('GET', $path);
            $container->setScoped(Request::class, $request);
            $service = $container->get('fiber.service');
            Fiber::suspend([$container->get(Request::class)->path(), spl_object_id($service)]);
            return [$container->get(Request::class)->path(), spl_object_id($container->get('fiber.service'))];
        } finally {
            $container->endScope();
        }
    };
    $first = new Fiber(static fn (): array => $run('/first'));
    $second = new Fiber(static fn (): array => $run('/second'));
    $firstStart = $first->start();
    $secondStart = $second->start();
    $first->resume();
    $second->resume();
    $expect($firstStart[0] === '/first' && $secondStart[0] === '/second', 'Chaque Fiber doit conserver sa propre requête.');
    $expect($firstStart[1] !== $secondStart[1], 'Chaque Fiber doit construire son propre service scoped.');
    $expect($first->getReturn() === $firstStart && $second->getReturn() === $secondStart, 'Le contexte doit rester stable après entrelacement.');
    $expect(!$container->hasActiveScope(), 'Les portées Fiber terminées doivent être entièrement libérées.');
});

$test('les parties statiques des routes sont échappées', function () use ($expect): void {
    $router = new Router(new Container());
    $router->add('GET', '/v1.0/{id}', [SecurityTestController::class, 'show']);
    $expect($router->dispatch(new Request('GET', '/v1.0/7'))->status() === 200, 'La route exacte doit correspondre.');
    $expect($router->dispatch(new Request('GET', '/v1X0/7'))->status() === 404, 'Le point statique ne doit pas agir comme une expression régulière.');
});

$test('l’index de routage conserve priorité, paramètres, noms et réponses 405', function () use ($expect): void {
    $router = new Router(new Container());
    $router->add('GET', '/users/{id}', [SecurityTestController::class, 'show'], name: 'users.show');
    $router->add('POST', '/users/{id}', [SecurityTestController::class, 'show']);
    $router->add('GET', '/{section}/{id}', [SecurityTestController::class, 'show']);
    for ($index = 0; $index < 500; $index++) {
        $router->add('GET', "/catalog/{$index}/{id}", [SecurityTestController::class, 'show']);
    }
    $matched = $router->dispatch(new Request('GET', '/catalog/499/73'));
    $method = $router->dispatch(new Request('DELETE', '/users/42'));
    $expect($matched->content() === '{"id":"73"}', 'L’index doit retrouver une route statique profonde avec paramètre.');
    $expect($method->status() === 405 && ($method->headers()['Allow'] ?? '') === 'GET, POST', 'L’index doit conserver la détection des méthodes autorisées.');
    $expect($router->url('users.show', ['id' => 'a/b']) === '/users/a%2Fb', 'L’index des noms doit conserver l’encodage des paramètres.');
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

$test('le contrat CSRF expose et renouvelle le jeton pour AML Engine', function () use ($expect): void {
    $session = new Session();
    $token = $session->token();
    $expect(str_contains($session->csrfMeta(), 'name="csrf-token"'), 'La balise meta CSRF doit être disponible.');
    $middleware = new CsrfMiddleware($session);
    $next = static fn (): Response => Response::json(['ok' => true]);
    $accepted = $middleware->process(new Request('POST', '/api/save', [], [], ['HTTP_X_CSRF_TOKEN' => $token]), $next);
    $expect($accepted->status() === 200 && ($accepted->headers()['X-CSRF-Token'] ?? '') === $token, 'Le jeton valide doit être accepté et renouvelé.');
    $rejected = $middleware->process(new Request('POST', '/api/save'), $next);
    $expect($rejected->status() === 419 && ($rejected->headers()['X-CSRF-Token'] ?? '') === $token, 'La réponse 419 doit fournir un nouveau jeton.');
});

$test('une destination AML View traverse le pipeline HTTP principal', function () use ($expect): void {
    $application = new WebApplication(['middlewares' => [SecurityTestMiddleware::class]]);
    $response = $application->handle(
        new Request('GET', '/declarative'),
        static fn (): Response => Response::html('<main>AML View</main>'),
    );
    $expect($response->status() === 200 && ($response->headers()['X-Test-Pipeline'] ?? '') === 'active', 'La destination déclarative doit traverser les middlewares globaux.');
});

$test('la CSP autorise uniquement le nonce du moteur AML View', function () use ($expect): void {
    $capturedNonce = null;
    $middleware = new SecurityHeadersMiddleware();
    $response = $middleware->process(
        new Request('GET', '/'),
        static function (Request $request) use (&$capturedNonce): Response {
            $capturedNonce = CspNonce::from($request);
            return Response::html('<script nonce="injected-value">unsafe</script>');
        },
    );
    $csp = $response->headers()['Content-Security-Policy'] ?? '';
    $expect(is_string($capturedNonce) && str_contains($csp, "script-src 'self' 'nonce-{$capturedNonce}'"), 'La CSP doit utiliser le nonce immuable de la requête.');
    $expect(!str_contains($csp, 'nonce-injected-value'), "Le contenu HTML ne doit jamais déterminer la politique CSP.");
});

$test('la sécurité HTTPS dépend de la requête courante et non des variables globales', function () use ($expect): void {
    $middleware = new SecurityHeadersMiddleware();
    $next = static fn (): Response => Response::html('ok');
    $secure = $middleware->process(new Request('GET', '/', [], [], ['HTTP_X_FORWARDED_PROTO' => 'https']), $next);
    $plain = $middleware->process(new Request('GET', '/', [], [], ['HTTPS' => 'off']), $next);
    $expect(isset($secure->headers()['Strict-Transport-Security']), 'Une requête HTTPS doit recevoir HSTS.');
    $expect(!isset($plain->headers()['Strict-Transport-Security']), 'Une requête HTTP ne doit pas recevoir HSTS.');
});

$test('la langue est résolue par route, cookie, en-tête puis repli', function () use ($expect): void {
    $active = [];
    $middleware = new LocaleMiddleware(
        ['en', 'fr-CA'],
        'en',
        ['route', 'cookie', 'header'],
        'phpaml_locale',
        static function (string $locale, Closure $next) use (&$active): Response {
            $active[] = $locale;
            return $next();
        },
    );
    $next = static fn (Request $request): Response => Response::json(['locale' => $request->attribute('locale')]);
    $route = $middleware->process(new Request('GET', '/fr/docs', [], [], ['HTTP_ACCEPT_LANGUAGE' => 'en'], ['phpaml_locale' => 'en']), $next);
    $cookie = $middleware->process(new Request('GET', '/docs', [], [], ['HTTP_ACCEPT_LANGUAGE' => 'en'], ['phpaml_locale' => 'fr-CA']), $next);
    $header = $middleware->process(new Request('GET', '/docs', [], [], ['HTTP_ACCEPT_LANGUAGE' => 'de;q=0.4, fr-FR;q=0.9, en;q=0.8']), $next);
    $fallback = $middleware->process(new Request('GET', '/docs', [], [], ['HTTP_ACCEPT_LANGUAGE' => 'de']), $next);
    $expect($active === ['fr-CA', 'fr-CA', 'fr-CA', 'en'], 'La priorité de détection des langues est incorrecte.');
    $expect(($route->headers()['Content-Language'] ?? '') === 'fr-CA', 'La réponse doit annoncer sa langue.');
    $expect(($route->headers()['Vary'] ?? '') === 'Accept-Language, Cookie', 'Les caches doivent varier selon les signaux de langue.');
    $expect($header->content() === '{"locale":"fr-CA"}' && $fallback->content() === '{"locale":"en"}', 'La langue doit être injectée dans la requête.');
});

$test('les erreurs CSRF et Rate Limit conservent les en-têtes de sécurité', function () use ($expect): void {
    $rateDirectory = sys_get_temp_dir() . '/phpaml-pipeline-rate-' . bin2hex(random_bytes(6));
    $application = new WebApplication([
        'middlewares' => [SecurityHeadersMiddleware::class],
        'rate_limit' => ['enabled' => true, 'storage_path' => $rateDirectory, 'limit' => 1, 'window' => 60],
    ]);
    $destination = static fn (): Response => Response::json(['ok' => true]);
    $csrf = $application->handle(new Request('POST', '/save'), $destination);
    $expect($csrf->status() === 419 && isset($csrf->headers()['Content-Security-Policy']), 'La réponse 419 doit conserver les protections HTTP.');

    $token = '';
    $application->handle(new Request('GET', '/token'), static function () use ($application, &$token): Response {
        $session = $application->container()->get(Session::class);
        $token = $session->token();
        return Response::html('ok');
    });
    $server = ['HTTP_X_CSRF_TOKEN' => $token, 'REMOTE_ADDR' => '127.0.0.1'];
    $application->handle(new Request('POST', '/save', [], [], $server), $destination);
    $limited = $application->handle(new Request('POST', '/save', [], [], $server), $destination);
    $expect($limited->status() === 429 && isset($limited->headers()['Content-Security-Policy']), 'La réponse 429 doit conserver les protections HTTP.');
    foreach (glob($rateDirectory . '/*') ?: [] as $file) { unlink($file); }
    if (is_dir($rateDirectory)) { rmdir($rateDirectory); }
});

$test('le mode API uniformise JSON, CORS et OPTIONS', function () use ($expect): void {
    $application = new WebApplication([
        'type' => 'api',
        'api' => [
            'enabled' => true,
            'prefix' => '/api/v1',
            'cors' => ['origins' => ['http://localhost:5173']],
        ],
    ]);
    $server = ['HTTP_ORIGIN' => 'http://localhost:5173', 'HTTP_ACCEPT' => 'application/json'];
    $response = $application->handle(
        new Request('GET', '/api/v1/health', [], [], $server),
        static fn (): Response => ApiResponse::ok(['status' => 'ok'])
    );
    $expect($response->status() === 200, 'La réponse API doit réussir.');
    $expect(($response->headers()['Access-Control-Allow-Origin'] ?? '') === 'http://localhost:5173', 'CORS doit autoriser Vue.');
    $options = $application->handle(new Request('OPTIONS', '/api/v1/health', [], [], $server));
    $expect($options->status() === 204, 'OPTIONS doit être automatique.');
    $write = $application->handle(
        new Request('POST', '/api/v1/movies', [], ['title' => 'Arrival'], $server),
        static fn (): Response => ApiResponse::created(['title' => 'Arrival']),
    );
    $expect($write->status() === 201, 'Une API pure ne doit pas exiger le jeton CSRF d’une session web.');
});

$test('les tokens API sont hashés, transmis et révocables', function () use ($expect): void {
    $path = sys_get_temp_dir() . '/phpaml-framework-token-' . bin2hex(random_bytes(6)) . '.json';
    $tokens = new TokenManager($path, 60);
    $plain = $tokens->issue(42, 'tests');
    $expect(!str_contains((string) file_get_contents($path), $plain), 'Le token brut ne doit pas être stocké.');
    $middleware = new ApiAuthMiddleware($tokens);
    $request = new Request('GET', '/api/v1/profile', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain]);
    $response = $middleware->process($request, static fn (Request $request): Response => ApiResponse::ok(['id' => $request->attribute('auth.id')]));
    $expect($response->content() === '{"data":{"id":"42"}}', 'L’identité authentifiée doit être transmise.');
    $expect($tokens->revoke($plain) && $tokens->authenticate($plain) === null, 'La révocation doit être immédiate.');
    unlink($path);
});

$test('les capacités, rotations et révocations globales des tokens sont appliquées', function () use ($expect): void {
    $path = sys_get_temp_dir() . '/phpaml-framework-abilities-' . bin2hex(random_bytes(6)) . '.json';
    $tokens = new TokenManager($path, 60);
    $read = $tokens->issue(7, 'vue', ['products.read']);
    $other = $tokens->issue(7, 'mobile', ['products.write']);
    $record = $tokens->authenticate($read);
    $expect(is_array($record) && $tokens->can($record, 'products.read') && !$tokens->can($record, 'products.write'), 'Les capacités doivent être strictement appliquées.');
    $request = (new Request('GET', '/api/v1/products'))->withAttribute('auth.token', $record)->withAttribute('auth.required_abilities', ['products.write']);
    $denied = (new AbilityMiddleware($tokens))->process($request, static fn (): Response => ApiResponse::ok([]));
    $expect($denied->status() === 403, 'Une capacité absente doit retourner 403.');
    $rotated = $tokens->rotate($read);
    $expect(is_string($rotated) && $tokens->authenticate($read) === null && $tokens->authenticate($rotated) !== null, 'La rotation doit invalider l’ancien token.');
    $expect($tokens->revokeOwner(7) === 2 && $tokens->authenticate($rotated) === null && $tokens->authenticate($other) === null, 'logout-all doit révoquer tous les tokens du propriétaire.');
    unlink($path);
});

$test('les capacités déclaratives de route sont appliquées par le routeur', function () use ($expect): void {
    $file = sys_get_temp_dir() . '/phpaml-route-ability-' . bin2hex(random_bytes(5)) . '.json';
    $tokens = new TokenManager($file, 60);
    $plain = $tokens->issue('7', 'route', ['records.read']);
    $container = new Container();
    $container->set(TokenManager::class, $tokens);
    $router = new Router($container);
    $router->addRoutes([
        'GET /records/{id}' => [
            'handler' => [SecurityTestController::class, 'show'],
            'middleware' => [ApiAuthMiddleware::class, AbilityMiddleware::class],
            'abilities' => ['records.read'],
        ],
        'DELETE /records/{id}' => [
            'handler' => [SecurityTestController::class, 'show'],
            'middleware' => [ApiAuthMiddleware::class, AbilityMiddleware::class],
            'abilities' => ['records.write'],
        ],
    ]);
    $server = ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain];
    $expect($router->dispatch(new Request('GET', '/records/1', [], [], $server))->status() === 200, 'La capacité de lecture devrait être acceptée.');
    $expect($router->dispatch(new Request('DELETE', '/records/1', [], [], $server))->status() === 403, 'La capacité d’écriture manquante devrait être refusée.');
    unlink($file);
});

$test('le parcours register login me logout est complet et ne divulgue jamais les mots de passe', function () use ($expect): void {
    $tokenPath = sys_get_temp_dir() . '/phpaml-framework-auth-tokens-' . bin2hex(random_bytes(6)) . '.json';
    $databasePath = sys_get_temp_dir() . '/phpaml-framework-auth-' . bin2hex(random_bytes(6)) . '.sqlite';
    $tokens = new TokenManager($tokenPath, 60);
    $auth = new AuthManager(new Connection('sqlite:' . $databasePath), $tokens);
    $controller = new AuthController($auth, $tokens);
    $registered = $controller->register(new Request('POST', '/api/v1/register', [], ['name' => 'Ada', 'email' => 'ADA@example.com', 'password' => 'correct-horse']));
    $payload = json_decode($registered->content(), true, 512, JSON_THROW_ON_ERROR);
    $plain = $payload['data']['token'] ?? '';
    $expect($registered->status() === 201 && is_string($plain) && $plain !== '' && !str_contains($registered->content(), 'password_hash'), 'register doit créer un compte et une session sûre.');
    try { $auth->login('ada@example.com', 'incorrect'); $expect(false, 'Un mauvais mot de passe doit être refusé.'); } catch (AuthException $error) { $expect($error->status === 401, 'Les identifiants invalides retournent 401.'); }
    $record = $tokens->authenticate($plain);
    $request = (new Request('GET', '/api/v1/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain]))->withAttribute('auth.id', $record['owner_id'] ?? '');
    $expect(str_contains($controller->me($request)->content(), 'ada@example.com'), 'me doit retourner le profil public.');
    $expect($controller->logout($request)->status() === 204 && $tokens->authenticate($plain) === null, 'logout doit révoquer le token courant.');
    if (is_file($tokenPath)) { unlink($tokenPath); }
    if (is_file($databasePath)) { unlink($databasePath); }
});

$test('la validation API retourne un contrat 422 uniforme', function () use ($expect): void {
    $requestType = new class extends ApiRequest {
        public function rules(): array { return ['name' => ['required', 'string'], 'price' => ['required', 'numeric']]; }
    };
    $application = new WebApplication(['api' => ['enabled' => true, 'prefix' => '/api/v1']]);
    $response = $application->handle(
        new Request('POST', '/api/v1/products', [], ['price' => 'invalid'], ['HTTP_AUTHORIZATION' => 'Bearer test']),
        static function (Request $request) use ($requestType): Response {
            $requestType->validated($request);
            return ApiResponse::created([]);
        }
    );
    $expect($response->status() === 422, 'Une validation API invalide doit retourner 422.');
    $expect(str_contains($response->content(), 'VALIDATION_FAILED') && str_contains($response->content(), 'name'), 'Les erreurs par champ doivent être exposées.');
});

$test('la validation riche couvre formats, comparaisons et base de données', function () use ($expect): void {
    $verifier = static fn (string $rule, string $table, string $column, mixed $value): bool => $rule === 'exists' && $table === 'users' && $column === 'id' && $value === 7;
    $validator = new Validator($verifier);
    $valid = $validator->validate([
        'website' => 'https://phpaml.test', 'uuid' => '550e8400-e29b-41d4-a716-446655440000',
        'birthday' => '2025-12-31', 'role' => 'admin', 'password' => 'secret12',
        'password_confirmation' => 'secret12', 'tags' => ['php', 'api'], 'owner_id' => 7,
        'optional' => null,
    ], [
        'website' => ['url'], 'uuid' => ['uuid'], 'birthday' => ['date_format:Y-m-d'],
        'role' => ['in:admin,user', 'not_in:blocked'], 'password' => ['confirmed', 'between:8,20'],
        'tags' => ['array', 'between:1,3'], 'owner_id' => ['exists:users,id'],
        'optional' => ['nullable', 'string'],
    ]);
    $expect($valid, 'Toutes les règles avancées valides doivent être acceptées.');
    $expect(!(new Validator())->validate(['code' => 'abc'], ['code' => ['regex:/^[0-9]+$/']]), 'Une expression régulière doit être appliquée.');
});

$test('les paramètres de collection refusent les champs non autorisés', function () use ($expect, $throws): void {
    $parser = new CollectionQuery(['available'], ['name', 'price'], ['name']);
    $result = $parser->parse(['filter' => ['available' => true], 'sort' => '-price,name', 'per_page' => 999, 'search' => ' phone ']);
    $expect($result['per_page'] === 100 && $result['sort'][0] === ['field' => 'price', 'direction' => 'desc'], 'Pagination et tri doivent être normalisés.');
    $expect($result['search'] === 'phone', 'La recherche doit être normalisée.');
    $throws(fn () => $parser->parse(['sort' => 'password_hash']));
    $throws(fn () => $parser->parse(['filter' => ['id; DROP TABLE users' => 1]]));
});

$test('les ressources exposent uniquement les champs et relations déclarés', function () use ($expect): void {
    $resource = new class(['id' => 1, 'name' => 'Produit', 'secret' => 'jamais']) extends ApiResource {
        protected function fields(): array { return ['id' => $this->value('id'), 'name' => $this->value('name')]; }
        protected function relations(): array { return ['owner' => new class(['name' => 'André', 'token' => 'secret']) extends ApiResource { protected function fields(): array { return ['name' => $this->value('name')]; } }]; }
    };
    $plain = $resource->resolve();
    $included = $resource->resolve(['owner', 'unknown']);
    $expect(!isset($plain['secret']) && !isset($plain['owner']), 'Les champs et relations non demandés ne doivent pas sortir.');
    $expect($included['owner'] === ['name' => 'André'], 'Une relation autorisée doit être sérialisée explicitement.');
});

$test('les téléversements vérifient contenu, taille et nom aléatoire', function () use ($expect, $throws): void {
    $directory = sys_get_temp_dir() . '/phpaml-upload-' . bin2hex(random_bytes(5));
    $temporary = tempnam(sys_get_temp_dir(), 'phpaml-file-');
    file_put_contents($temporary, 'contenu texte');
    $upload = new UploadedFile(['name' => '../../danger.php', 'tmp_name' => $temporary, 'error' => UPLOAD_ERR_OK, 'size' => filesize($temporary)]);
    $name = $upload->store($directory, ['text/plain'], 1000);
    $expect(preg_match('/^[a-f0-9]{40}\\.txt$/', $name) === 1 && is_file($directory . '/' . $name), 'Le nom client ne doit jamais être réutilisé.');
    $bad = tempnam(sys_get_temp_dir(), 'phpaml-file-'); file_put_contents($bad, 'x');
    $throws(fn () => (new UploadedFile(['tmp_name' => $bad, 'error' => UPLOAD_ERR_OK, 'size' => 1]))->store($directory, ['image/png']));
    unlink($bad); unlink($directory . '/' . $name); rmdir($directory);
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

$test('la limitation Redis utilise une identité distribuée', function () use ($expect): void {
    $redis = new class {
        public array $values = []; public array $ttls = [];
        public function incr(string $key): int { return $this->values[$key] = ($this->values[$key] ?? 0) + 1; }
        public function expire(string $key, int $ttl): bool { $this->ttls[$key] = $ttl; return true; }
        public function ttl(string $key): int { return $this->ttls[$key] ?? -1; }
    };
    $limiter = new RedisRateLimitMiddleware($redis, 1, 30);
    $request = new Request('GET', '/api/v1/items', [], [], ['REMOTE_ADDR' => '127.0.0.1']);
    $next = static fn (): Response => ApiResponse::ok([]);
    $expect($limiter->process($request, $next)->status() === 200, 'La première requête Redis doit passer.');
    $expect($limiter->process($request, $next)->status() === 429, 'La limite Redis doit être distribuée.');
});

$test('observabilité, cache HTTP et version API ajoutent leurs en-têtes', function () use ($expect): void {
    $observed = null;
    $requestIds = new RequestIdMiddleware(static function (array $record) use (&$observed): void { $observed = $record; });
    $request = new Request('GET', '/api/v1/items', [], [], ['HTTP_X_REQUEST_ID' => 'request-1234']);
    $response = $requestIds->process($request, static fn (Request $request): Response => Response::json(['request_id' => $request->attribute('request_id')]));
    $expect(($response->headers()['X-Request-ID'] ?? '') === 'request-1234' && ($observed['status'] ?? 0) === 200, "L'identifiant et la mesure doivent être disponibles.");

    $cache = new HttpCacheMiddleware(60, true);
    $cached = $cache->process($request, static fn (): Response => Response::json(['id' => 1]));
    $etag = $cached->headers()['ETag'] ?? '';
    $notModified = $cache->process(new Request('GET', '/api/v1/items', [], [], ['HTTP_IF_NONE_MATCH' => $etag]), static fn (): Response => Response::json(['id' => 1]));
    $expect($notModified->status() === 304 && $notModified->content() === '', 'Un ETag identique doit produire une réponse 304 vide.');

    $versioned = (new ApiVersionMiddleware('v1', '2027-01-01', '2027-06-01', '/api/v2'))->process($request, static fn (): Response => Response::json([]));
    $expect(isset($versioned->headers()['Deprecation'], $versioned->headers()['Sunset']) && $versioned->headers()['API-Version'] === 'v1', 'La dépréciation doit être annoncée.');
});

$test('les écritures idempotentes sont rejouées et les conflits refusés', function () use ($expect): void {
    $directory = sys_get_temp_dir() . '/phpaml-idempotency-' . bin2hex(random_bytes(6));
    $middleware = new IdempotencyMiddleware(new FileIdempotencyStore($directory, 60));
    $calls = 0;
    $next = static function () use (&$calls): Response { $calls++; return Response::json(['number' => $calls], 201); };
    $server = ['HTTP_IDEMPOTENCY_KEY' => 'create-item-123'];
    $userA = fn (array $input): Request => (new Request('POST', '/items', [], $input, $server))->withAttribute('auth.id', 'user-a');
    $userB = fn (array $input): Request => (new Request('POST', '/items', [], $input, $server))->withAttribute('auth.id', 'user-b');
    $first = $middleware->process($userA(['name' => 'A']), $next);
    $second = $middleware->process($userA(['name' => 'A']), $next);
    $conflict = $middleware->process($userA(['name' => 'B']), $next);
    $expect($first->status() === 201 && $second->status() === 201 && $calls === 1, 'La même écriture ne doit être exécutée qu’une fois.');
    $expect(($second->headers()['Idempotency-Replayed'] ?? '') === 'true' && $conflict->status() === 409, 'Le rejeu et le conflit doivent être explicites.');
    $otherUser = $middleware->process($userB(['name' => 'A']), $next);
    $expect($otherUser->status() === 201 && $calls === 2 && $otherUser->content() !== $first->content(), 'Deux utilisateurs ne doivent jamais partager une réponse idempotente.');
    foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
    rmdir($directory);
});

$test('l’idempotence authentifie avant tout rejeu et isole les propriétaires', function () use ($expect): void {
    $directory = sys_get_temp_dir() . '/phpaml-idempotency-auth-' . bin2hex(random_bytes(6));
    $tokenPath = sys_get_temp_dir() . '/phpaml-idempotency-tokens-' . bin2hex(random_bytes(6)) . '.json';
    $tokens = new TokenManager($tokenPath, 60);
    $tokenA = $tokens->issue('user-a');
    $tokenB = $tokens->issue('user-b');
    $middleware = new IdempotencyMiddleware(new FileIdempotencyStore($directory, 60), ['POST'], $tokens);
    $auth = new ApiAuthMiddleware($tokens);
    $calls = 0;
    $next = static function (Request $request) use ($auth, &$calls): Response {
        return $auth->process($request, static function (Request $authenticated) use (&$calls): Response {
            $calls++;
            return Response::json(['owner' => $authenticated->attribute('auth.id'), 'call' => $calls], 201);
        });
    };
    $request = static fn (string $token): Request => new Request('POST', '/private', [], ['value' => 1], [
        'HTTP_IDEMPOTENCY_KEY' => 'private-operation',
        'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
    ]);
    $firstA = $middleware->process($request($tokenA), $next);
    $replayA = $middleware->process($request($tokenA), $next);
    $firstB = $middleware->process($request($tokenB), $next);
    $tokens->revoke($tokenB);
    $revokedB = $middleware->process($request($tokenB), $next);
    $invalid = $middleware->process($request('invalid-token'), $next);
    $expect($firstA->status() === 201 && $replayA->content() === $firstA->content(), 'Le rejeu authentifié du même utilisateur a échoué.');
    $expect($firstB->status() === 201 && $firstB->content() !== $firstA->content() && $calls === 2, 'Les propriétaires partagent encore une réponse privée.');
    $expect($revokedB->status() === 401 && $invalid->status() === 401, 'Un token révoqué ou invalide a pu atteindre le cache.');
    foreach (glob($directory . '/*') ?: [] as $file) { @unlink($file); }
    @rmdir($directory); @unlink($tokenPath); @unlink($tokenPath . '.lock');
});

$test('le stockage des tokens résiste aux créations et rotations concurrentes', function () use ($expect): void {
    $path = sys_get_temp_dir() . '/phpaml-token-concurrency-' . bin2hex(random_bytes(6)) . '.json';
    $processes = [];
    for ($worker = 0; $worker < 8; $worker++) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, 'token-worker', $path, 'owner-' . $worker, '50'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Impossible de démarrer un processus de test.'); }
        $processes[] = [$process, $pipes];
    }
    foreach ($processes as [$process, $pipes]) {
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $expect(proc_close($process) === 0, 'Une création concurrente a échoué : ' . trim($error));
    }
    $records = json_decode((string) file_get_contents($path), true);
    $hashes = array_column(is_array($records) ? $records : [], 'hash');
    $expect(count($hashes) === 400 && count(array_unique($hashes)) === 400, 'Des tokens concurrents ont été perdus ou dupliqués.');

    $manager = new TokenManager($path, 300);
    $original = $manager->issue('rotation-owner');
    $rotations = [];
    for ($worker = 0; $worker < 8; $worker++) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, 'rotate-worker', $path, $original], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Impossible de démarrer une rotation concurrente.'); }
        $rotations[] = [$process, $pipes];
    }
    $successful = [];
    foreach ($rotations as [$process, $pipes]) {
        $replacement = trim((string) stream_get_contents($pipes[1]));
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $expect(proc_close($process) === 0, 'Une rotation concurrente a échoué : ' . trim($error));
        if ($replacement !== '') { $successful[] = $replacement; }
    }
    $expect(count($successful) === 1, 'Une seule rotation concurrente doit réussir.');
    $expect($manager->authenticate($original) === null && $manager->authenticate($successful[0]) !== null, 'La rotation atomique a produit un état incohérent.');
    @unlink($path); @unlink($path . '.lock');
});

$test('OpenAPI génère un client TypeScript utilisable', function () use ($expect): void {
    $openApi = (new OpenApiGenerator('Shop', '1.0'))->generate([
        'GET /api/v1/products/{id}' => ['name' => 'products.show'],
        'POST /api/v1/products' => ['name' => 'products.create'],
    ], 'https://api.example.test');
    $client = (new TypeScriptClientGenerator())->generate($openApi);
    $expect(isset($openApi['paths']['/api/v1/products/{id}']['get']), 'La route doit apparaître dans OpenAPI.');
    $expect(str_contains($client, 'productsShow(id: string | number)') && str_contains($client, 'Authorization'), 'Le client doit typer les chemins et gérer Bearer.');
});

$failed = 0;
foreach ($tests as $name => $case) {
    try { $case(); echo "✓ {$name}\n"; } catch (Throwable $error) { fwrite(STDERR, "✗ {$name}: {$error->getMessage()}\n"); $failed++; }
}
exit($failed === 0 ? 0 : 1);
