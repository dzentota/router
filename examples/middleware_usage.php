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
use dzentota\Router\Middleware\Cache\ArrayCache;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use dzentota\TypedValue\Typed;
use dzentota\TypedValue\TypedValue;
use dzentota\TypedValue\ValidationResult;

/**
 * Complete middleware stack example — run with PHP's built-in server:
 *
 *   php -S localhost:8000 -t examples/
 *
 * Endpoints:
 *   GET  /                  — welcome JSON
 *   GET  /form              — HTML form protected by CSRF + CSP nonce
 *   GET  /api/users/{id}    — typed route parameter demo
 *   POST /api/users         — CSRF-protected JSON endpoint
 */

// ---------------------------------------------------------------------------
// Typed route parameter
// ---------------------------------------------------------------------------

class UserId implements Typed
{
    use TypedValue;

    public static function validate($value): ValidationResult
    {
        $result = new ValidationResult();
        if (!is_numeric($value) || $value <= 0) {
            $result->addError('Invalid user ID — must be a positive integer');
        }
        return $result;
    }

    public function toNative(): int
    {
        return (int) $this->value;
    }
}

// ---------------------------------------------------------------------------
// Normalise request URI when served via built-in server with a filename prefix
// ---------------------------------------------------------------------------

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$uri = preg_replace('#^/middleware_usage\.php#', '', $uri) ?: '/';

// ---------------------------------------------------------------------------
// Routes
// ---------------------------------------------------------------------------

$router = new Router();

$router->get('/', function (): array {
    return ['message' => 'Welcome to the secure API'];
});

$router->get('/api/users/{id}', function (ServerRequestInterface $request): array {
    /** @var UserId $id */
    $id = $request->getAttribute('id');
    return [
        'user' => ['id' => $id->toNative(), 'name' => 'Jane Doe', 'email' => 'jane@example.com'],
    ];
}, ['id' => UserId::class], 'api.users.show');

$router->post('/api/users', function (ServerRequestInterface $request): array {
    $data = $request->getParsedBody() ?? [];
    return ['message' => 'User created', 'data' => $data];
}, [], 'api.users.create');

$router->get('/form', function (ServerRequestInterface $request): string {
    $cookies    = $request->getCookieParams();
    $csrfCookie = $cookies['csrf-token'] ?? '';
    $csrfToken  = $request->getAttribute('csrf_token', $csrfCookie);
    $nonce      = $request->getAttribute('csp_nonce', '');
    $timestamp  = time();

    return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Secure Form</title>
            <style nonce="{$nonce}">
                body { font-family: Arial, sans-serif; max-width: 640px; margin: 2rem auto; padding: 0 1rem; }
                form { background: #f9f9f9; padding: 1.5rem; border-radius: 6px; margin-top: 1.5rem; }
                label { display: block; font-weight: bold; margin-bottom: 4px; }
                input[type=text], input[type=email] { width: 100%; padding: 8px; margin-bottom: 1rem; box-sizing: border-box; }
                button { background: #2d6a4f; color: #fff; padding: 10px 18px; border: none; border-radius: 4px; cursor: pointer; }
                pre { background: #f0f0f0; padding: 1rem; border-radius: 4px; overflow-x: auto; }
                .error { color: #c00; }
                .honeypot { display: none; }
            </style>
            <script nonce="{$nonce}">
                function getCsrfToken() {
                    return document.cookie.split(';')
                        .map(c => c.trim().split('='))
                        .find(([name]) => name === 'csrf-token')
                        ?.[1] ?? null;
                }

                document.addEventListener('DOMContentLoaded', () => {
                    document.getElementById('secure-form').addEventListener('submit', async (e) => {
                        e.preventDefault();
                        const token = getCsrfToken();
                        if (!token) {
                            document.getElementById('result').innerHTML = "<span class='error'>CSRF token cookie not found.</span>";
                            return;
                        }
                        document.getElementById('result').textContent = 'Submitting…';
                        try {
                            const res = await fetch('/middleware_usage.php/api/users', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                                body: JSON.stringify({
                                    name:  document.getElementById('name').value,
                                    email: document.getElementById('email').value,
                                }),
                            });
                            const data = await res.json();
                            document.getElementById('result').textContent = JSON.stringify(data, null, 2);
                        } catch (err) {
                            document.getElementById('result').innerHTML = "<span class='error'>Error: " + err.message + "</span>";
                        }
                    });
                });
            </script>
        </head>
        <body>
            <h1>Secure Form</h1>
            <p>This form demonstrates:</p>
            <ul>
                <li>CSP with per-request nonce (prevents inline XSS)</li>
                <li>CSRF double-submit cookie protection</li>
                <li>Honeypot fields to detect bots</li>
                <li>Minimum submission time threshold</li>
            </ul>
            <form id="secure-form">
                <input type="hidden" name="_token"     value="{$csrfToken}">
                <input type="hidden" name="_timestamp" value="{$timestamp}">

                <!-- Honeypot fields — must stay empty for legitimate users -->
                <div class="honeypot" aria-hidden="true">
                    <input type="text" name="website"       tabindex="-1" autocomplete="off">
                    <input type="text" name="confirm_email" tabindex="-1" autocomplete="off">
                </div>

                <label for="name">Name</label>
                <input type="text"  id="name"  name="name"  required>

                <label for="email">Email</label>
                <input type="email" id="email" name="email" required>

                <button type="submit">Submit</button>
                <pre id="result"></pre>
            </form>
        </body>
        </html>
    HTML;
});

// ---------------------------------------------------------------------------
// PSR-7 request from globals
// ---------------------------------------------------------------------------

$factory      = new Psr17Factory();
$method       = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$serverParams = $_SERVER;
$headers      = [];

foreach ($serverParams as $key => $value) {
    if (str_starts_with($key, 'HTTP_')) {
        $headers[str_replace('_', '-', substr($key, 5))] = $value;
    }
}

$parsedBody = null;
if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $parsedBody  = str_contains($contentType, 'application/json')
        ? (json_decode(file_get_contents('php://input'), true) ?? [])
        : ($_POST ?? []);
}

$request = (new ServerRequest($method, $uri, $headers, null, '1.1', $serverParams))
    ->withCookieParams($_COOKIE ?? [])
    ->withQueryParams($_GET ?? []);

if ($parsedBody !== null) {
    $request = $request->withParsedBody($parsedBody);
}

// ---------------------------------------------------------------------------
// Middleware
// ---------------------------------------------------------------------------

$tokenGenerator = new TokenGenerator();

// CSP — nonce generation enabled; unsafe directives intentionally omitted
$cspMiddleware = new CspMiddleware([], false, '', true, $tokenGenerator);

// CSRF — development cookie (no Secure flag for localhost).
// In production: use __Host- prefix, secure: true, and a real secret.
$csrfStrategy = new SignedDoubleSubmitCookieStrategy(
    $tokenGenerator,
    getenv('CSRF_SECRET') ?: 'change-me-in-production',
    'csrf-token',
    ['secure' => false, 'samesite' => 'Lax', 'httponly' => false, 'path' => '/']
);

// Enable CSRF rate limiting: block an IP after 5 failures within 1 hour.
$csrfMiddleware = new CsrfMiddleware(
    strategy:             $csrfStrategy,
    exemptRoutes:         ['/api/webhook'],
    cache:                new ArrayCache(),
    maxFailedAttempts:    5,
    failureWindowSeconds: 3600,
);

// CORS — in production, replace '*' with your exact front-end origin(s).
$corsMiddleware = new CorsMiddleware([
    'allowed_origins'      => ['http://localhost:8000'],
    'allowed_methods'      => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
    'allowed_headers'      => ['Content-Type', 'Authorization', 'X-CSRF-TOKEN'],
    'allow_credentials'    => true,
    'max_age'              => 86400,
    'require_exact_origin' => true,
]);

$honeypotMiddleware = new HoneypotMiddleware(['website', 'confirm_email'], 2);

$handler = MiddlewareStack::create(
    new class implements RequestHandlerInterface {
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            $response = (new Psr17Factory())->createResponse(404);
            $response->getBody()->write(json_encode(['error' => 'Not Found', 'status' => 404]));
            return $response->withHeader('Content-Type', 'application/json');
        }
    },
    $corsMiddleware,
    $cspMiddleware,
    $csrfMiddleware,
    $honeypotMiddleware,
    new RouteMatchMiddleware($router),
    new RouteDispatchMiddleware()
);

// ---------------------------------------------------------------------------
// Dispatch and emit response
// ---------------------------------------------------------------------------

$response = $handler->handle($request);

http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header("{$name}: {$value}", false);
    }
}
echo $response->getBody();
