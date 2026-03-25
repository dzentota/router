<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use dzentota\Router\Router;
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

class UserId implements Typed
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

echo "🚀 Router Middleware Demo\n";
echo "========================\n\n";

$factory = new Psr17Factory();

// ---------------------------------------------------------------------------
// Route validation — path traversal and duplicate params are caught eagerly
// ---------------------------------------------------------------------------

echo "--- Route validation ---\n";

try {
    $bad = new Router();
    $bad->get('/admin/../etc/passwd', 'action');
} catch (InvalidRouteException $e) {
    echo "✓ Path traversal rejected: {$e->getMessage()}\n";
}

try {
    $bad = new Router();
    $bad->get('/users/{id}/posts/{id}', 'action', ['id' => UserId::class]);
} catch (InvalidRouteException $e) {
    echo "✓ Duplicate parameter rejected: {$e->getMessage()}\n";
}

echo "\n";

// ---------------------------------------------------------------------------
// Router setup
// ---------------------------------------------------------------------------

$router = new Router();

$router->get('/', function () {
    return ['message' => 'Welcome to the secure API', 'status' => 'success'];
});

$router->get('/api/users/{id}', function (ServerRequestInterface $request) {
    $id = $request->getAttribute('id');
    return [
        'user' => ['id' => $id->toNative(), 'name' => 'John Doe'],
        'status' => 'success',
    ];
}, ['id' => UserId::class], 'api.users.show');

$router->post('/api/users', function (ServerRequestInterface $request) {
    $data = $request->getParsedBody();
    return ['message' => 'User created', 'data' => $data, 'status' => 'success'];
}, [], 'api.users.create');

// Named-route URL generation
echo "--- URL generation ---\n";
echo "✓ " . $router->generateUrl('api.users.show', ['id' => '42']) . "\n";
echo "✓ " . $router->generateUrl('api.users.create') . "\n\n";

// ---------------------------------------------------------------------------
// Middleware setup
// ---------------------------------------------------------------------------

$finalHandler = new class($factory) implements RequestHandlerInterface {
    public function __construct(private Psr17Factory $factory) {}
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->factory->createResponse(404);
        $response->getBody()->write(json_encode(['error' => 'Not Found']));
        return $response->withHeader('Content-Type', 'application/json');
    }
};

// CORS — explicit origin whitelist
$corsMiddleware = new CorsMiddleware([
    'allowed_origins'      => ['https://app.example.com', 'https://admin.example.com'],
    'allowed_methods'      => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
    'allowed_headers'      => ['Content-Type', 'Authorization', 'X-CSRF-TOKEN'],
    'exposed_headers'      => ['X-CSRF-TOKEN'],
    'allow_credentials'    => true,
    'max_age'              => 86400,
    'require_exact_origin' => true,
]);

// CSP — tight policy; unsafe directives require explicit opt-in
$cspMiddleware = new CspMiddleware([
    'default-src'      => ["'self'"],
    'script-src'       => ["'self'", 'https://cdn.jsdelivr.net'],
    'style-src'        => ["'self'", 'https://fonts.googleapis.com'],
    'img-src'          => ["'self'", 'data:', 'https:'],
    'object-src'       => ["'none'"],
    'frame-ancestors'  => ["'none'"],
    'form-action'      => ["'self'"],
    'base-uri'         => ["'self'"],
], false, '/csp-report', true);

// Honeypot — hidden fields + minimum submission time
$honeypotMiddleware = new HoneypotMiddleware(
    ['website', 'url', 'confirm_email'],
    2,
    true
);

// CSRF — stateless signed-cookie strategy.
// Pass a PSR-16 cache to enable per-IP failure rate limiting (5 failures / hour).
$tokenGenerator = new TokenGenerator();
$csrfStrategy   = new SignedDoubleSubmitCookieStrategy(
    $tokenGenerator,
    'demo-secret-key-change-in-production',
    '__Host-csrf-token'
);
$csrfMiddleware = new CsrfMiddleware(
    strategy:             $csrfStrategy,
    cache:                new ArrayCache(),   // enables rate limiting
    maxFailedAttempts:    5,
    failureWindowSeconds: 3600,
);

$middlewareStack = MiddlewareStack::create(
    $finalHandler,
    $corsMiddleware,
    $cspMiddleware,
    $honeypotMiddleware,
    $csrfMiddleware,
    new RouteMatchMiddleware($router),
    new RouteDispatchMiddleware()
);

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

function testRoute(RequestHandlerInterface $stack, string $method, string $uri, ?array $body = null, array $cookies = []): void
{
    echo "  {$method} {$uri}\n";

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
    $summary  = $decoded ? (
        isset($decoded['error']) ? "❌ {$status} — {$decoded['error']}" : "✓ {$status} — " . ($decoded['message'] ?? json_encode($decoded))
    ) : "→ {$status}";

    echo "  {$summary}\n\n";
}

// ---------------------------------------------------------------------------
// Test scenarios
// ---------------------------------------------------------------------------

echo "--- Middleware stack scenarios ---\n\n";

testRoute($middlewareStack, 'GET', '/');
testRoute($middlewareStack, 'GET', '/api/users/123');

echo "  POST /api/users (no CSRF token — expect 403)\n";
testRoute($middlewareStack, 'POST', '/api/users', ['name' => 'Alice', 'email' => 'alice@example.com']);

echo "  POST /api/users (valid CSRF token — expect 200)\n";
$csrfToken = $tokenGenerator->generateToken();
testRoute(
    $middlewareStack,
    'POST',
    '/api/users',
    ['name' => 'Alice', 'email' => 'alice@example.com', '_token' => $csrfStrategy->getTokenSignature($csrfToken)],
    ['__Host-csrf-token' => $csrfToken]
);

echo "  POST /api/users (honeypot field filled — expect 429/403)\n";
testRoute(
    $middlewareStack,
    'POST',
    '/api/users',
    ['name' => 'Bot', 'website' => 'http://spam.com', '_token' => $csrfStrategy->getTokenSignature($csrfToken)],
    ['__Host-csrf-token' => $csrfToken]
);

testRoute($middlewareStack, 'GET', '/nonexistent');

echo "Demo complete! 🎉\n";

