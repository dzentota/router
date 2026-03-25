<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use dzentota\Router\Attribute\RouteAttribute;
use dzentota\Router\Loader\AttributeLoader;
use dzentota\Router\Router;
use dzentota\Router\UrlSigner;
use dzentota\Router\Exception\InvalidConstraintException;
use dzentota\Router\Exception\InvalidRouteException;
use dzentota\Router\Middleware\MiddlewareStack;
use dzentota\Router\Middleware\CorsMiddleware;
use dzentota\Router\Middleware\CspMiddleware;
use dzentota\Router\Middleware\CsrfMiddleware;
use dzentota\Router\Middleware\HoneypotMiddleware;
use dzentota\Router\Middleware\RouteMatchMiddleware;
use dzentota\Router\Middleware\RouteDispatchMiddleware;
use dzentota\Router\Middleware\Security\SignedDoubleSubmitCookieStrategy;
use dzentota\Router\Middleware\Security\TokenGenerator;
use dzentota\Router\Middleware\Cache\ArrayCache;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use dzentota\TypedValue\Typed;
use dzentota\TypedValue\TypedValue;
use dzentota\TypedValue\ValidationResult;

// ---------------------------------------------------------------------------
// Typed value constraints
// ---------------------------------------------------------------------------

class DemoUserId implements Typed
{
    use TypedValue;

    public static function validate($value): ValidationResult
    {
        $result = new ValidationResult();
        if (!is_numeric($value) || $value <= 0) {
            $result->addError('Invalid user ID');
        }
        return $result;
    }
}

class DemoPostId implements Typed
{
    use TypedValue;

    public static function validate($value): ValidationResult
    {
        $result = new ValidationResult();
        if (!is_numeric($value) || $value <= 0) {
            $result->addError('Invalid post ID');
        }
        return $result;
    }
}

// ---------------------------------------------------------------------------
// PHP 8 attribute-annotated controller
// ---------------------------------------------------------------------------

#[RouteAttribute('/api/v1')]
class DemoAttrController
{
    #[RouteAttribute('/status', methods: 'GET', name: 'api.status', tags: ['api', 'public'])]
    public function status(): array
    {
        return ['status' => 'ok', 'version' => '1.0'];
    }

    #[RouteAttribute('/users/{id}', methods: 'GET', constraints: ['id' => DemoUserId::class],
                     name: 'api.users.show', tags: ['api'])]
    public function showUser(ServerRequestInterface $request): array
    {
        $id = $request->getAttribute('id');
        return ['user' => ['id' => $id->toNative(), 'name' => 'Alice']];
    }
}

// ---------------------------------------------------------------------------
// Resource controller stub
// ---------------------------------------------------------------------------

class DemoPostController
{
    public function index(ServerRequestInterface $request): array  { return ['posts' => []]; }
    public function create(ServerRequestInterface $request): array { return ['form' => 'create']; }
    public function store(ServerRequestInterface $request): array  { return ['message' => 'stored']; }
    public function show(ServerRequestInterface $request, DemoPostId $id): array
    {
        return ['post' => ['id' => $id->toNative()]];
    }
    public function edit(ServerRequestInterface $request): array   { return ['form' => 'edit']; }
    public function update(ServerRequestInterface $request): array { return ['message' => 'updated']; }
    public function destroy(ServerRequestInterface $request): array { return ['message' => 'deleted']; }
}

echo "🚀 Router Feature Demo\n";
echo "======================\n\n";

$factory = new Psr17Factory();

// ===========================================================================
// 1. Eager validation — caught at registration time, not at runtime
// ===========================================================================

echo "--- 1. Eager route validation ---\n";

try {
    (new Router())->get('/admin/../etc/passwd', 'action');
} catch (InvalidRouteException $e) {
    echo "✓ Path traversal rejected: {$e->getMessage()}\n";
}

try {
    (new Router())->get('/users/{id}/posts/{id}', 'action', ['id' => DemoUserId::class]);
} catch (InvalidRouteException $e) {
    echo "✓ Duplicate parameter rejected: {$e->getMessage()}\n";
}

try {
    (new Router())->get('/items/{id}', 'action')->where(['id' => 'not-a-typed-class']);
} catch (InvalidConstraintException $e) {
    echo "✓ Non-Typed constraint rejected: {$e->getMessage()}\n";
}

echo "\n";

// ===========================================================================
// 2. Fluent route API
// ===========================================================================

echo "--- 2. Fluent route API ---\n";

$router = new Router();

$router->get('/users/{id}', function (ServerRequestInterface $request) {
    $id = $request->getAttribute('id');
    return ['user' => ['id' => $id->toNative()]];
})
->where(['id' => DemoUserId::class])
->name('users.show')
->tag(['api', 'users']);

$router->get('/posts/{page?}', function (ServerRequestInterface $request) {
    $page = $request->getAttribute('page', 1);
    return ['page' => is_object($page) ? $page->toNative() : $page];
})
->where(['page' => DemoUserId::class])
->defaults(['page' => 1])
->name('posts.index')
->tag('api');

echo "✓ Named route '/users/{id}' → " . $router->generateUrl('users.show', ['id' => '42']) . "\n";
echo "✓ Default page on '/posts'  → page=" . $router->findRoute('GET', '/posts')['params']['page'] . "\n";
echo "✓ Explicit page on '/posts/3' → page=" . $router->findRoute('GET', '/posts/3')['params']['page']->toNative() . "\n";

echo "\n";

// ===========================================================================
// 3. resource() + apiResource() macros
// ===========================================================================

echo "--- 3. Resource macros ---\n";

$resRouter = new Router();
$resRouter->resource('/posts', DemoPostController::class, ['id' => DemoPostId::class]);
$resRouter->apiResource('/api/comments', DemoPostController::class, ['id' => DemoPostId::class]);

echo "✓ resource('/posts') registered: " . implode(', ', array_keys($resRouter->getNamedRoutes())) . "\n";
echo "✓ apiResource URL: " . $resRouter->generateUrl('api.comments.show', ['id' => '7']) . "\n";

echo "\n";

// ===========================================================================
// 4. PHP 8 Attribute loader
// ===========================================================================

echo "--- 4. Attribute-based routing ---\n";

$attrRouter = new Router();
(new AttributeLoader($attrRouter))->loadFromClass(DemoAttrController::class);

echo "✓ Loaded routes: " . implode(', ', array_keys($attrRouter->getNamedRoutes())) . "\n";
echo "✓ URL: " . $attrRouter->generateUrl('api.users.show', ['id' => '5']) . "\n";

echo "\n";

// ===========================================================================
// 5. Auto-naming
// ===========================================================================

echo "--- 5. Auto-naming ---\n";

$autoRouter = new Router();
$autoRouter->enableAutoNaming();
$autoRouter->get('/dashboard', 'Dashboard');
$autoRouter->get('/admin/users/{id}', 'AdminUserShow', ['id' => DemoUserId::class]);
$autoRouter->get('/explicit', 'Explicit', [], 'explicit.route'); // explicit wins

echo "✓ /dashboard → name: '" . ($autoRouter->findNameForRoute('/dashboard', 'GET') ?? 'n/a') . "'\n";
echo "✓ /admin/users/{id} → name: '" . ($autoRouter->findNameForRoute('/admin/users/{id}', 'GET') ?? 'n/a') . "'\n";
echo "✓ /explicit → name: '" . ($autoRouter->findNameForRoute('/explicit', 'GET') ?? 'n/a') . "'\n";

echo "\n";

// ===========================================================================
// 6. Tags + getRoutesByTag() + getStats()
// ===========================================================================

echo "--- 6. Tags and statistics ---\n";

$tagRouter = new Router();
$tagRouter->get('/api/users',   'UserIndex' )->tag(['api', 'public'])->name('users.index');
$tagRouter->get('/api/users/{id}', 'UserShow')->where(['id' => DemoUserId::class])->tag('api')->name('users.show');
$tagRouter->get('/admin/dashboard', 'AdminDash')->tag('admin')->name('admin.dashboard');
$tagRouter->get('/health', 'Health');

$apiRoutes = $tagRouter->getRoutesByTag('api');
echo "✓ Routes tagged 'api': " . count($apiRoutes) . "\n";

$stats = $tagRouter->getStats();
echo "✓ Stats: total={$stats['total']}, named={$stats['named']}, tagged={$stats['tagged']}\n";
echo "✓ Tags:  " . json_encode($stats['tags']) . "\n";

echo "\n";

// ===========================================================================
// 7. Signed URLs
// ===========================================================================

echo "--- 7. Signed URLs ---\n";

$sigRouter  = new Router();
$sigRouter->get('/invoices/{id}/download', 'InvoiceDownload', ['id' => DemoUserId::class], 'invoices.download');
$signer     = new UrlSigner($sigRouter, 'demo-key-must-be-at-least-32-chars', 3600);

$signed = $signer->sign('invoices.download', ['id' => '42'], 3600);
echo "✓ Signed: {$signed}\n";
echo "✓ Verify (valid):   " . ($signer->verify($signed)  ? 'true' : 'false') . "\n";

$expired = $signer->sign('invoices.download', ['id' => '42'], -1);
echo "✓ Verify (expired): " . ($signer->verify($expired) ? 'true' : 'false') . "\n";

$tampered = preg_replace('/signature=[a-f0-9]+/', 'signature=deadbeef', $signed);
echo "✓ Verify (tampered): " . ($signer->verify($tampered) ? 'true' : 'false') . "\n";

echo "\n";

// ===========================================================================
// 8. Controller::method string format
// ===========================================================================

echo "--- 8. Controller::method handler format ---\n";

$dispatchRouter = new Router();
$dispatchRouter->get('/ping', 'DemoPostController::index');
$result = $dispatchRouter->findRoute('GET', '/ping');
echo "✓ Handler stored as: " . $result['action'] . "\n";

echo "\n";

// ===========================================================================
// 9. Full middleware stack demo
// ===========================================================================

echo "--- 9. Middleware stack ---\n\n";

$mainRouter = new Router();
$mainRouter->get('/', fn() => ['message' => 'Welcome!', 'status' => 'success']);
$mainRouter->get('/api/users/{id}', function (ServerRequestInterface $request) {
    $id = $request->getAttribute('id');
    return ['user' => ['id' => $id->toNative(), 'name' => 'Alice'], 'status' => 'success'];
}, ['id' => DemoUserId::class], 'api.users.show');
$mainRouter->post('/api/users', fn() => ['message' => 'User created', 'status' => 'success'], [], 'api.users.create');

$finalHandler = new class($factory) implements RequestHandlerInterface {
    public function __construct(private Psr17Factory $factory) {}
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->factory->createResponse(404);
        $response->getBody()->write(json_encode(['error' => 'Not Found']));
        return $response->withHeader('Content-Type', 'application/json');
    }
};

$tokenGenerator = new TokenGenerator();
$csrfStrategy   = new SignedDoubleSubmitCookieStrategy(
    $tokenGenerator,
    'demo-csrf-secret-change-in-production',
    '__Host-csrf-token'
);

$middlewareStack = MiddlewareStack::create(
    $finalHandler,
    new CorsMiddleware([
        'allowed_origins'      => ['https://app.example.com'],
        'allowed_methods'      => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        'allowed_headers'      => ['Content-Type', 'Authorization', 'X-CSRF-TOKEN'],
        'allow_credentials'    => true,
        'require_exact_origin' => true,
    ]),
    new CspMiddleware([
        'default-src' => ["'self'"],
        'script-src'  => ["'self'", 'https://cdn.jsdelivr.net'],
        'object-src'  => ["'none'"],
    ], false, '/csp-report', true),
    new HoneypotMiddleware(['website', 'url'], 2, true),
    new CsrfMiddleware(
        strategy:             $csrfStrategy,
        cache:                new ArrayCache(),
        maxFailedAttempts:    5,
        failureWindowSeconds: 3600,
    ),
    new RouteMatchMiddleware($mainRouter),
    new RouteDispatchMiddleware()
);

function demoRequest(RequestHandlerInterface $stack, string $method, string $uri, ?array $body = null, array $cookies = []): void
{
    $request = new ServerRequest($method, $uri);
    if ($body !== null) {
        $request = $request->withParsedBody($body);
    }
    if (!empty($cookies)) {
        $request = $request->withCookieParams($cookies);
    }
    $response = $stack->handle($request);
    $status   = $response->getStatusCode();
    $decoded  = json_decode((string)$response->getBody(), true);
    $icon     = $status < 400 ? '✓' : '✗';
    $msg      = $decoded['message'] ?? $decoded['error'] ?? $decoded['status'] ?? '—';
    echo "  {$icon} {$method} {$uri} → {$status} ({$msg})\n";
}

demoRequest($middlewareStack, 'GET',  '/');
demoRequest($middlewareStack, 'GET',  '/api/users/123');
demoRequest($middlewareStack, 'POST', '/api/users', ['name' => 'Bob']); // no CSRF
$csrfToken = $tokenGenerator->generateToken();
demoRequest($middlewareStack, 'POST', '/api/users',
    ['name' => 'Bob', '_token' => $csrfStrategy->getTokenSignature($csrfToken)],
    ['__Host-csrf-token' => $csrfToken]);
demoRequest($middlewareStack, 'GET',  '/nonexistent');

echo "\n✅ Demo complete!\n";

