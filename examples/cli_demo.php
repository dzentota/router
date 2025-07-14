<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use dzentota\Router\Router;
use dzentota\Router\Middleware\MiddlewareStack;
use dzentota\Router\Middleware\CorsMiddleware;
use dzentota\Router\Middleware\CspMiddleware;
use dzentota\Router\Middleware\CsrfMiddleware;
use dzentota\Router\Middleware\HoneypotMiddleware;
use dzentota\Router\Middleware\RouteMatchMiddleware;
use dzentota\Router\Middleware\RouteDispatchMiddleware;
use dzentota\Router\Middleware\Security\SignedDoubleSubmitCookieStrategy;
use dzentota\Router\Middleware\Security\TokenGenerator;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use dzentota\TypedValue\Typed;
use dzentota\TypedValue\TypedValue;
use dzentota\TypedValue\ValidationResult;

// Example TypedValue class for UserId - defined at the top level
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

/**
 * CLI Demo - Middleware Stack Example
 * 
 * This example demonstrates the middleware functionality in a CLI environment.
 */

echo "🚀 Router Middleware Demo\n";
echo "========================\n\n";

// Initialize PSR-7 factory
$factory = new Psr17Factory();

// Setup Router with example routes
$router = new Router();

// Add some routes
$router->get('/', function() {
    return ['message' => 'Welcome to the secure API', 'status' => 'success'];
});

$router->get('/api/users/{id}', function(ServerRequestInterface $request) {
    $id = $request->getAttribute('id');
    
    return [
        'user' => [
            'id' => $id->toNative(),
            'name' => 'John Doe',
            'email' => 'john@example.com'
        ],
        'status' => 'success'
    ];
}, ['id' => UserId::class], 'api.users.show');

$router->post('/api/users', function(ServerRequestInterface $request) {
    $data = $request->getParsedBody();
    return ['message' => 'User created', 'data' => $data, 'status' => 'success'];
}, [], 'api.users.create');

// Final handler (404 fallback)
$finalHandler = new class($factory) implements RequestHandlerInterface {
    private $factory;
    
    public function __construct(Psr17Factory $factory) {
        $this->factory = $factory;
    }
    
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->factory->createResponse(404);
        $response->getBody()->write(json_encode([
            'error' => 'Not Found',
            'message' => 'The requested resource was not found',
            'status' => 'error'
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }
};

// Setup Security Middlewares

// 1. CORS Middleware
$corsMiddleware = new CorsMiddleware([
    'allowed_origins' => ['https://app.example.com', 'https://admin.example.com'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
    'allowed_headers' => ['Content-Type', 'Authorization', 'X-CSRF-TOKEN'],
    'exposed_headers' => ['X-CSRF-TOKEN'],
    'allow_credentials' => true,
    'max_age' => 86400
]);

// 2. CSP Middleware 
$cspMiddleware = new CspMiddleware([
    'default-src' => ["'self'"],
    'script-src' => ["'self'", 'https://cdn.jsdelivr.net'],
    'style-src' => ["'self'", 'https://fonts.googleapis.com'],
    'img-src' => ["'self'", 'data:', 'https:'],
    'object-src' => ["'none'"],
    'frame-ancestors' => ["'none'"],
    'form-action' => ["'self'"],
    'base-uri' => ["'self'"]
], false, '/csp-report', true);

// 3. Honeypot Middleware
$honeypotMiddleware = new HoneypotMiddleware(
    ['website', 'url', 'confirm_email'],
    2,
    true
);

// 4. CSRF Middleware
$tokenGenerator = new TokenGenerator();
$csrfStrategy = new SignedDoubleSubmitCookieStrategy(
    $tokenGenerator,
    'demo-secret-key-for-testing',
    '__Host-csrf-token'
);
$csrfMiddleware = new CsrfMiddleware($csrfStrategy);

// 5. Route Middlewares
$routeMatchMiddleware = new RouteMatchMiddleware($router);
$routeDispatchMiddleware = new RouteDispatchMiddleware();

// Build Middleware Stack
$middlewareStack = MiddlewareStack::create(
    $finalHandler,
    $corsMiddleware,
    $cspMiddleware,
    $honeypotMiddleware,
    $csrfMiddleware,
    $routeMatchMiddleware,
    $routeDispatchMiddleware
);

// Demo function to test routes
function testRoute($stack, $method, $uri, $body = null, $cookies = []) {
    echo "Testing: $method $uri\n";
    
    $factory = new Psr17Factory();
    $request = new ServerRequest($method, $uri);
    
    if ($body) {
        $request = $request->withParsedBody($body);
    }
    
    if (!empty($cookies)) {
        $request = $request->withCookieParams($cookies);
    }
    
    try {
        $response = $stack->handle($request);
        
        echo "Status: " . $response->getStatusCode() . "\n";
        
        // Show response headers
        $headers = $response->getHeaders();
        if (!empty($headers)) {
            echo "Headers:\n";
            foreach ($headers as $name => $values) {
                echo "  $name: " . implode(', ', $values) . "\n";
            }
        }
        
        // Show response body
        $body = (string)$response->getBody();
        if (!empty($body)) {
            $decoded = json_decode($body, true);
            if ($decoded) {
                echo "Response: " . json_encode($decoded, JSON_PRETTY_PRINT) . "\n";
            } else {
                echo "Response: $body\n";
            }
        }
        
    } catch (\Throwable $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
    
    echo "\n" . str_repeat('-', 50) . "\n\n";
}

// Run demo tests
echo "Testing GET / (should work)\n";
testRoute($middlewareStack, 'GET', '/');

echo "Testing GET /api/users/123 (should work)\n";
testRoute($middlewareStack, 'GET', '/api/users/123');

echo "Testing POST /api/users (should be blocked by CSRF)\n";
testRoute($middlewareStack, 'POST', '/api/users', ['name' => 'John', 'email' => 'john@example.com']);

echo "Testing POST /api/users with CSRF token (should work)\n";
$csrfToken = $tokenGenerator->generateToken();
$csrfSignature = $csrfStrategy->getTokenSignature($csrfToken);
testRoute(
    $middlewareStack, 
    'POST', 
    '/api/users', 
    ['name' => 'John', 'email' => 'john@example.com'],
    ['__Host-csrf-token' => $csrfToken]
);

echo "Testing POST with honeypot field filled (should be blocked)\n";
testRoute(
    $middlewareStack, 
    'POST', 
    '/api/users', 
    ['name' => 'John', 'website' => 'http://spam.com', '_token' => $csrfSignature],
    ['__Host-csrf-token' => $csrfToken]
);

echo "Testing non-existent route (should return 404)\n";
testRoute($middlewareStack, 'GET', '/nonexistent');

echo "Demo completed! 🎉\n";
echo "\nThis demonstrates:\n";
echo "- Route matching and dispatch\n";
echo "- CSRF protection (blocks requests without valid tokens)\n";
echo "- Honeypot protection (blocks requests with filled honeypot fields)\n";
echo "- CORS headers being added to responses\n";
echo "- CSP headers being added to responses\n";
echo "- Proper error handling (404 for non-existent routes)\n"; 