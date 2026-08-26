<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Autoloader.php';
PHPAML\Autoloader::register(['PHPAML\\' => dirname(__DIR__) . '/src']);

use PHPAML\Container;
use PHPAML\Config\ApplicationConfig;
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
use PHPAML\Security\CspNonce;
use PHPAML\Session\Session;
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

final class SecurityTestController { public function show(Request $request): Response { return Response::json(['id' => $request->attribute('id')]); } }
final class SecurityTestMiddleware implements MiddlewareInterface { public function process(Request $request, Closure $next): Response { return $next($request)->withHeader('X-Test-Pipeline', 'active'); } }
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
    ], JSON_THROW_ON_ERROR));
    file_put_contents($root . '/.env', "APP_DEBUG=true\nDATABASE_USER=demo\n");
    $config = ApplicationConfig::load($root);
    $expect($config['name'] === 'configuration-test' && $config['debug'] === true, '.env doit surcharger phpaml.json.');
    $expect($config['rate_limit']['limit'] === 25 && $config['database']['username'] === 'demo', 'Les réglages déclaratifs doivent être normalisés.');
    $expect($config['api']['tokens']['storage_path'] === $root . '/runtime/storage/tokens.json', 'La configuration API doit être normalisée.');
    $expect($config['data']['connections']['main']['database'] === $root . '/runtime/storage/data.sqlite', 'La configuration Data doit être normalisée.');
    $expect(is_file($root . '/runtime/config/app.php'), 'Le cache runtime/config/app.php doit être généré.');
    unlink($root . '/runtime/config/app.php'); rmdir($root . '/runtime/config'); rmdir($root . '/runtime');
    unlink($root . '/.env'); unlink($root . '/phpaml.json'); rmdir($root . '/app/views'); rmdir($root . '/app'); rmdir($root);
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

$test('les erreurs CSRF et Rate Limit conservent les en-têtes de sécurité', function () use ($expect): void {
    $rateDirectory = sys_get_temp_dir() . '/phpaml-pipeline-rate-' . bin2hex(random_bytes(6));
    $application = new WebApplication([
        'middlewares' => [SecurityHeadersMiddleware::class],
        'rate_limit' => ['enabled' => true, 'storage_path' => $rateDirectory, 'limit' => 1, 'window' => 60],
    ]);
    $destination = static fn (): Response => Response::json(['ok' => true]);
    $csrf = $application->handle(new Request('POST', '/save'), $destination);
    $expect($csrf->status() === 419 && isset($csrf->headers()['Content-Security-Policy']), 'La réponse 419 doit conserver les protections HTTP.');

    $session = $application->container()->get(Session::class);
    $server = ['HTTP_X_CSRF_TOKEN' => $session->token(), 'REMOTE_ADDR' => '127.0.0.1'];
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
    $first = $middleware->process(new Request('POST', '/items', [], ['name' => 'A'], $server), $next);
    $second = $middleware->process(new Request('POST', '/items', [], ['name' => 'A'], $server), $next);
    $conflict = $middleware->process(new Request('POST', '/items', [], ['name' => 'B'], $server), $next);
    $expect($first->status() === 201 && $second->status() === 201 && $calls === 1, 'La même écriture ne doit être exécutée qu’une fois.');
    $expect(($second->headers()['Idempotency-Replayed'] ?? '') === 'true' && $conflict->status() === 409, 'Le rejeu et le conflit doivent être explicites.');
    foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
    rmdir($directory);
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
