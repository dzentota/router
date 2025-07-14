<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../tests/MockUserId.php'; // Importing UserId class for route parameters

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

/**
 * Complete Middleware Stack Example
 * 
 * This example shows how to use all security middlewares together
 * to create a robust, secure web application.
 */

// Initialize PSR-7 factory
$factory = new Psr17Factory();

// Setup Router with some example routes
$router = new Router();

// Process URI to work properly with PHP's built-in server
$uri = $_SERVER['REQUEST_URI'] ?? '/';
// If URI contains filename (middleware_usage.php), remove it
$uri = preg_replace('#^/middleware_usage\.php#', '', $uri);
// If URI is empty after removing the filename, use "/"
if (empty($uri)) {
    $uri = '/';
}

// Add some routes
$router->get('/', function() {
    return ['message' => 'Welcome to the secure API'];
});

// Используем анонимную функцию вместо строки с именем контроллера
$router->get('/api/users/{id}', function(ServerRequestInterface $request) {
    $controller = new UserController();
    return $controller->show($request);
}, ['id' => \dzentota\TypedValue\UserId::class], 'api.users.show');

$router->post('/api/users', function(ServerRequestInterface $request) {
    $data = $request->getParsedBody();
    return ['message' => 'User created', 'data' => $data];
}, [], 'api.users.create');

$router->get('/form', function(ServerRequestInterface $request) {
    // Получаем CSRF-токен из cookie (для сравнения)
    $cookies = $request->getCookieParams();
    // Изменяем имя cookie без префикса __Host для локальной разработки
    $csrfCookie = $cookies['csrf-token'] ?? '';

    // Получаем атрибуты из запроса
    $csrfToken = $request->getAttribute('csrf_token', $csrfCookie);
    $nonce = $request->getAttribute('csp_nonce', '');
    $timestamp = time();

    return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <title>Secure Form</title>
            <style nonce="{$nonce}">
                body {
                    font-family: Arial, sans-serif;
                    max-width: 800px;
                    margin: 0 auto;
                    padding: 20px;
                }
                form {
                    background-color: #f9f9f9;
                    padding: 20px;
                    border-radius: 5px;
                    margin-top: 20px;
                }
                label {
                    display: block;
                    margin-bottom: 5px;
                    font-weight: bold;
                }
                input {
                    width: 100%;
                    padding: 8px;
                    margin-bottom: 15px;
                    box-sizing: border-box;
                }
                button {
                    background-color: #4CAF50;
                    color: white;
                    padding: 10px 15px;
                    border: none;
                    border-radius: 4px;
                    cursor: pointer;
                }
                pre {
                    background-color: #f0f0f0;
                    padding: 15px;
                    border-radius: 4px;
                    margin-top: 20px;
                    overflow-x: auto;
                }
                .error {
                    color: #cc0000;
                }
                .honeypot {
                    display: none;
                }
            </style>
            <script nonce="{$nonce}">
                // Get CSRF token from cookie for AJAX requests
                function getCsrfToken() {
                    const cookies = document.cookie.split(";");
                    for (let cookie of cookies) {
                        const [name, value] = cookie.trim().split("=");
                        // Изменяем имя cookie, которое ищем в JavaScript
                        if (name === "csrf-token") {
                            return decodeURIComponent(value);
                        }
                    }
                    return null;
                }
                
                // Example AJAX request with CSRF protection
                function submitForm(event) {
                    // Prevent the default form submission
                    event.preventDefault();
                    
                    const token = getCsrfToken();
                    
                    // Debug information about token
                    console.log("CSRF token from cookie:", token);
                    console.log("All cookies:", document.cookie);
                    
                    if (!token) {
                        document.getElementById("result").innerHTML = "<span class='error'>CSRF token not found in cookies. Check browser settings.</span>";
                        return false;
                    }
                    
                    document.getElementById("result").textContent = "Submitting...";
                    
                    fetch("/middleware_usage.php/api/users", {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/json",
                            "X-CSRF-TOKEN": token
                        },
                        body: JSON.stringify({
                            name: document.getElementById("name").value,
                            email: document.getElementById("email").value
                        })
                    }).then(response => {
                        if (!response.ok) {
                            throw new Error("HTTP error! Status: " + response.status);
                        }
                        return response.json();
                    })
                    .then(data => {
                        document.getElementById("result").textContent = JSON.stringify(data, null, 2);
                    })
                    .catch(error => {
                        console.error("Error:", error);
                        document.getElementById("result").innerHTML = "<span class='error'>Error: " + error.message + "</span>";
                    });
                }
                
                // Add event listener when DOM is loaded
                document.addEventListener('DOMContentLoaded', function() {
                    const form = document.getElementById('secure-form');
                    if (form) {
                        form.addEventListener('submit', submitForm);
                        
                        // Debug information
                        console.log("Form initialized with CSRF protection");
                        console.log("Current cookies:", document.cookie);
                    }
                });
            </script>
        </head>
        <body>
            <h1>Secure Form with Middleware Protection</h1>
            <p>This form demonstrates the following security features:</p>
            <ul>
                <li>CSP (Content Security Policy) with nonce</li>
                <li>CSRF protection with double submit cookie pattern</li>
                <li>Honeypot fields to catch bots</li>
                <li>Form submission timing verification</li>
            </ul>
            <form id="secure-form">
                <input type="hidden" name="_token" value="{$csrfToken}">
                <input type="hidden" name="_timestamp" value="{$timestamp}">
                
                <!-- Honeypot fields (should remain empty) -->
                <div class="honeypot" aria-hidden="true">
                    <input type="text" name="website" autocomplete="off">
                    <input type="text" name="confirm_email" autocomplete="off">
                </div>
                
                <div>
                    <label for="name">Name:</label>
                    <input type="text" id="name" name="name" required>
                </div>
                <div>
                    <label for="email">Email:</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <div>
                    <button type="submit">Submit</button>
                </div>
                
                <pre id="result"></pre>
            </form>
        </body>
        </html>
HTML;
});

// Create PSR-7 ServerRequest from globals
$serverParams = $_SERVER;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$cookies = $_COOKIE ?? [];
$queryParams = $_GET ?? [];
$headers = [];

// Extract headers from $_SERVER
foreach ($serverParams as $key => $value) {
    if (strpos($key, 'HTTP_') === 0) {
        $name = str_replace('_', '-', substr($key, 5));
        $headers[$name] = $value;
    }
}

// Add host header if not already set
if (!isset($headers['HOST']) && isset($_SERVER['HTTP_HOST'])) {
    $headers['HOST'] = $_SERVER['HTTP_HOST'];
}

$parsedBody = null;

// Parse body based on method
if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (strpos($contentType, 'application/json') !== false) {
        $parsedBody = json_decode(file_get_contents('php://input'), true) ?? [];
    } else {
        $parsedBody = $_POST ?? [];
    }
}

$request = new ServerRequest(
    $method,
    $uri,
    $headers,
    null,
    '1.1',
    $serverParams
);

$request = $request->withCookieParams($cookies)
                   ->withQueryParams($queryParams);

if ($parsedBody !== null) {
    $request = $request->withParsedBody($parsedBody);
}

// Initialize token generator
$tokenGenerator = new TokenGenerator();

// Create CSP Middleware with nonce generation
// This middleware adds Content Security Policy headers and generates nonces for inline scripts and styles
// We're using our refactored TokenGenerator for generating secure nonces instead of the original implementation
$cspMiddleware = new CspMiddleware([], false, '', true, $tokenGenerator);

// Create CSRF Middleware with development-friendly cookie settings
$cookieOptions = [
    'secure' => false,      // Disable secure attribute for localhost development
    'samesite' => 'Lax',    // Keep Lax samesite policy
    'httponly' => false,    // Keep httponly false so JavaScript can read it
    'path' => '/'           // Site-wide cookie
];
$csrfStrategy = new SignedDoubleSubmitCookieStrategy(
    $tokenGenerator,
    'your-secure-key-here',
    'csrf-token',          // Изменяем имя cookie на более простое без префикса __Host
    $cookieOptions
);
$csrfMiddleware = new CsrfMiddleware($csrfStrategy, null, ['/api/webhook']);

// exclude route API from CSRF-check for testing purposes
$csrfMiddleware->addExemptRoute('/api/users');

// Create CORS Middleware (for API endpoints)
$corsMiddleware = new CorsMiddleware([
    'allowed_origins' => ['*'], // In production, specify exact origins
    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
    'allowed_headers' => ['Content-Type', 'Authorization', 'X-CSRF-TOKEN'],
    'allow_credentials' => true,
    'max_age' => 86400, // 1 day
    'require_exact_origin' => false, // For testing only
]);

// Create Honeypot Middleware
$honeypotMiddleware = new HoneypotMiddleware(['website', 'confirm_email'], 2);

// Create Route matching and dispatching middleware
$routeMatchMiddleware = new RouteMatchMiddleware($router);
$routeDispatchMiddleware = new RouteDispatchMiddleware();

// Create the middleware stack (order matters!)
$handler = MiddlewareStack::create(
    new class implements RequestHandlerInterface {
        public function handle(ServerRequestInterface $request): ResponseInterface {
            // This is our final fallback handler
            $response = (new Psr17Factory())->createResponse(404);
            $response->getBody()->write(json_encode([
                'error' => 'Not Found',
                'status' => 404,
                'timestamp' => date('c')
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }
    },
    $corsMiddleware,          // CORS checks first
    $cspMiddleware,           // Add CSP headers and nonce
    $csrfMiddleware,          // CSRF protection
    $honeypotMiddleware,      // Bot/spam protection
    $routeMatchMiddleware,    // Match route
    $routeDispatchMiddleware  // Dispatch to handler
);

// Process the request through the middleware stack
$response = $handler->handle($request);

// Send the response
http_response_code($response->getStatusCode());

foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header("$name: $value", false);
    }
}

echo $response->getBody()->__toString();

// Example Controller Class
class UserController
{
    public function show(ServerRequestInterface $request): array
    {
        try {
            // Логируем все атрибуты запроса для отладки
            error_log('DEBUG: Все атрибуты запроса: ' . print_r($request->getAttributes(), true));

            $id = $request->getAttribute('id');
            error_log('DEBUG: Тип id: ' . (is_object($id) ? get_class($id) : gettype($id)));

            // Проверяем, что $id существует и является объектом
            if (!is_object($id)) {
                return [
                    'error' => 'ID parameter is not an object',
                    'actual_type' => gettype($id),
                    'status' => 500,
                    'timestamp' => date('c')
                ];
            }

            // Проверяем, что у объекта есть метод toNative
            if (!method_exists($id, 'toNative')) {
                return [
                    'error' => 'ID object does not have toNative method',
                    'class' => get_class($id),
                    'methods' => get_class_methods($id),
                    'status' => 500,
                    'timestamp' => date('c')
                ];
            }

            // Пытаемся преобразовать ID в нативный тип
            $idValue = $id->toNative();

            // Возвращаем данные пользователя
            return [
                'user' => [
                    'id' => $idValue,
                    'name' => 'John Doe',
                    'email' => 'john@example.com'
                ]
            ];
        } catch (\Throwable $e) {
            // Подробное логирование ошибки
            error_log('ERROR: ' . $e->getMessage() . ' в файле ' . $e->getFile() . ' на строке ' . $e->getLine());
            error_log('ERROR: Стек вызовов: ' . $e->getTraceAsString());

            return [
                'error' => 'Exception occurred: ' . $e->getMessage(),
                'exception_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'status' => 500,
                'timestamp' => date('c')
            ];
        }
    }
}
