# dzentota/router

A high-performance, security-first PHP router with comprehensive PSR-15 middleware suite.

## Features

- **High Performance**: Optimized route matching with tree-based algorithm
- **Type Safety**: Strongly-typed route parameters with validation
- **PSR-15 Compliant**: Full middleware support with PSR-15 interface
- **Security First**: Built-in security middleware suite
- **Production Ready**: Comprehensive error handling and logging
- **Flexible**: Support for closures, controllers, and dependency injection

## Security Middleware Suite

The router includes a comprehensive security middleware suite designed to protect against common web vulnerabilities:

### 🔒 CSRF Protection
- Stateless and stateful protection strategies
- Cryptographically secure token generation
- HMAC-signed cookies for stateless protection
- PSR-16 cache integration for stateful protection

### 🛡️ Content Security Policy (CSP)
- Comprehensive CSP headers with nonce generation
- Configurable policy directives
- Report-only mode support
- Secure defaults for modern web applications

### 🌐 CORS Protection
- Full CORS policy implementation
- Preflight request handling
- Origin, method, and header validation
- Credential support with security-first defaults

### 🕷️ Honeypot Protection
- Bot detection using hidden fields
- Timing analysis for request patterns
- Rate limiting with exponential backoff
- Comprehensive logging and monitoring

## Quick Start

### Installation

```bash
composer require dzentota/router
```

### Basic Usage

```php
<?php

use dzentota\Router\Router;
use dzentota\Router\Middleware\MiddlewareStack;
use dzentota\Router\Middleware\RouteMatchMiddleware;
use dzentota\Router\Middleware\RouteDispatchMiddleware;
use Psr\Http\Message\ServerRequestInterface;

// Create router
$router = new Router();

// Add routes with type constraints
$router->get('/', function() {
    return ['message' => 'Hello World'];
});

$router->get('/users/{id}', function(ServerRequestInterface $request) {
    $id = $request->getAttribute('id');
    return ['user' => ['id' => $id->toNative()]];
}, ['id' => UserId::class]);

$router->post('/users', function(ServerRequestInterface $request) {
    $data = $request->getParsedBody();
    return ['message' => 'User created', 'data' => $data];
});

// Create middleware stack
$middlewareStack = MiddlewareStack::create(
    $finalHandler,
    new RouteMatchMiddleware($router),
    new RouteDispatchMiddleware()
);

// Handle request
$response = $middlewareStack->handle($request);
```

### Security-First Setup

```php
<?php

use dzentota\Router\Router;
use dzentota\Router\Middleware\MiddlewareStack;
use dzentota\Router\Middleware\CorsMiddleware;
use dzentota\Router\Middleware\CspMiddleware;
use dzentota\Router\Middleware\CsrfMiddleware;
use dzentota\Router\Middleware\HoneypotMiddleware;
use dzentota\Router\Middleware\RouteMatchMiddleware;
use dzentota\Router\Middleware\RouteDispatchMiddleware;
use dzentota\Router\Middleware\Builder\CspMiddlewareBuilder;
use dzentota\Router\Middleware\Builder\HoneypotMiddlewareBuilder;

$router = new Router();

// Add your routes here...

// Using builders to configure middleware
$cspMiddleware = CspMiddlewareBuilder::create()
    ->allowScriptFrom('https://cdn.jsdelivr.net')
    ->allowStyleFrom('https://fonts.googleapis.com')
    ->withReportUri('https://example.com/csp-report')
    ->withNonce(true)
    ->build();

$honeypotMiddleware = HoneypotMiddlewareBuilder::create()
    ->withHoneypotFields(['website', 'url', 'email_confirm'])
    ->withMinTimeThreshold(3)
    ->withMaxSubmissionsPerMinute(10)
    ->build();

// Build secure middleware stack
$middlewareStack = MiddlewareStack::create(
    $finalHandler,
    new CorsMiddleware([
        'allowed_origins' => ['https://app.example.com'],
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE'],
        'allow_credentials' => true
    ]),
    $cspMiddleware,
    $honeypotMiddleware,
    new CsrfMiddleware($csrfStrategy),
    new RouteMatchMiddleware($router),
    new RouteDispatchMiddleware()
);
```

## Type Safety

The router enforces type safety through strongly-typed route parameters:

```php
<?php

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

// Route with type constraint
$router->get('/users/{id}', 'UserController@show', ['id' => UserId::class]);
```

## Route Types

### Basic Routes
```php
$router->get('/', function() {
    return 'Hello World';
});

$router->post('/users', function() {
    return 'User created';
});
```

### Named Routes
```php
$router->get('/users/{id}', 'UserController@show', ['id' => UserId::class], 'users.show');

// Generate URL
$url = $router->generateUrl('users.show', ['id' => 123]);
```

### Route Groups
```php
$router->addGroup('/api/v1', function(Router $router) {
    $router->get('/users', 'UserController@index');
    $router->post('/users', 'UserController@store');
    $router->get('/users/{id}', 'UserController@show', ['id' => UserId::class]);
});
```

## Middleware

### Built-in Middleware

- **RouteMatchMiddleware**: Matches requests to routes
- **RouteDispatchMiddleware**: Executes route handlers
- **CsrfMiddleware**: CSRF protection
- **CspMiddleware**: Content Security Policy
- **CorsMiddleware**: Cross-Origin Resource Sharing
- **HoneypotMiddleware**: Bot detection

### Builder Pattern for Middleware Configuration

The router provides builder classes for easy middleware configuration:

#### CORS Builder

```php
use dzentota\Router\Middleware\Builder\CorsMiddlewareBuilder;

$corsMiddleware = CorsMiddlewareBuilder::create()
    ->withAllowedOrigins(['https://example.com'])
    ->addAllowedOrigin('https://api.example.com')
    ->withAllowedMethods(['GET', 'POST', 'PUT'])
    ->withAllowedHeaders(['Content-Type', 'Authorization'])
    ->allowCredentials(true)
    ->withMaxAge(3600)
    ->requireExactOrigin(true)
    ->build();
```

#### CSP Builder

```php
use dzentota\Router\Middleware\Builder\CspMiddlewareBuilder;

$cspMiddleware = CspMiddlewareBuilder::create()
    ->allowScriptFrom('https://cdn.jsdelivr.net')
    ->allowStyleFrom('https://fonts.googleapis.com')
    ->withReportUri('https://example.com/csp-report')
    ->allowInlineScripts() // Be careful with this one
    ->withNonce(true)
    ->reportOnly(false)
    ->build();
```

#### Honeypot Builder

```php
use dzentota\Router\Middleware\Builder\HoneypotMiddlewareBuilder;

$honeypotMiddleware = HoneypotMiddlewareBuilder::create()
    ->withHoneypotFields(['website', 'url', 'email_confirm'])
    ->withMinTimeThreshold(3)
    ->withMaxSubmissionsPerMinute(10)
    ->withBlockOnViolation(true)
    ->build();
```

### Custom Middleware

```php
<?php

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class LoggingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start = microtime(true);
        
        $response = $handler->handle($request);
        
        $duration = microtime(true) - $start;
        error_log("Request processed in {$duration}s");
        
        return $response;
    }
}
```

## Error Handling

The router provides comprehensive error handling:

```php
try {
    $route = $router->findRoute($method, $uri);
} catch (NotFoundException $e) {
    // Handle 404
    return new NotFoundResponse();
} catch (MethodNotAllowedException $e) {
    // Handle 405
    return new MethodNotAllowedResponse($e->getAllowedMethods());
}
```

## Performance

### Route Caching

```php
// Generate route cache
$cacheData = $router->dump();
file_put_contents('routes.cache', serialize($cacheData));

// Load cached routes
$router->load(unserialize(file_get_contents('routes.cache')));
```

## Testing

```bash
# Run all tests
composer test

# Run with coverage
composer test -- --coverage-html coverage/
```

## Examples

See the `examples/` directory for complete working examples:

- `cli_demo.php` - Complete middleware stack demonstration
- `middleware_usage.php` - Web-based middleware usage

## Running Examples

The library includes example applications to demonstrate various features and security middleware implementations.

### Using PHP's Built-in Web Server

You can quickly run the examples using PHP's built-in web server:

```bash
# Navigate to the project root
cd /path/to/router

# Start the built-in web server
php -S localhost:8000 -t examples/

# Access the middleware example
# http://localhost:8000/middleware_usage.php

# Access the API endpoint example
# http://localhost:8000/middleware_usage.php/api/users/1
```

This allows you to test the router's features and security middleware without configuring a full web server.

## Security Principles

This library implements security principles outlined in the [AppSecManifesto](https://github.com/dzentota/AppSecManifesto). Below are the key principles followed:

### Rule #0: Absolute Zero – Minimizing Attack Surface

The router minimizes attack surface by:
- Using strongly typed route parameters to prevent unexpected inputs
- Implementing precise route matching algorithms to avoid routing ambiguity
- Structuring the middleware stack to reject invalid requests early

### Rule #1: The Lord of the Sinks – Context-Specific Escaping

The library handles data output securely by:
- Implementing proper context-specific escaping in CSP middleware
- Using proper content-type headers to prevent content-type sniffing
- Ensuring proper encoding of route parameters

### Rule #2: The Parser's Prerogative (Least Computational Power Principle)

Input validation follows strict parsing principles:
- Route parameters are parsed and validated immediately at the routing boundary
- Strong typing ensures data conforms to expected format before processing
- Invalid inputs fail fast and explicitly

### Rule #3: Forget-me-not – Preserving Data Validity

The router maintains data validity through:
- Using TypedValue objects to carry validation state throughout request processing
- Ensuring validated data remains valid across the entire request lifecycle
- Type safety preserves the validity guarantees established during parsing

### Rule #5: The Principle of Pruning (Least Privilege)

The middleware stack enforces least privilege by:
- Implementing strict CORS policies that limit which origins can access resources
- Providing fine-grained control over allowed HTTP methods and headers
- Enforcing rigorous CSRF protection to prevent unauthorized actions

### Rule #6: The Castle Doctrine (Defense in Depth)

The library implements multiple security layers:
- CORS protection for controlling cross-origin access
- CSP headers to prevent XSS attacks
- CSRF protection to prevent cross-site request forgery
- Honeypot fields to detect and block automated attacks
- Strict input validation through type constraints

### Rule #10: The Gatekeeper's Gambit (Secure Session Management)

Session security is enhanced through:
- CSRF tokens with proper cryptographic properties
- Secure cookie configurations (SameSite, Secure, HttpOnly)
- Token generation with proper entropy

### Rule #12: The Sentinel's Shield (API Security)

API endpoints are secured through:
- Strong input validation via typed route parameters
- Protection against CSRF attacks
- Proper rate limiting and throttling options
- Configurable CORS policies to control API access

## Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for more details.

## License

This project is licensed under the MIT License - see the LICENSE file for details.

## Security

If you discover a security vulnerability, please report it privately to the maintainers. Do not disclose it publicly until it has been addressed.

## Contributing

Please read [CONTRIBUTING.md](CONTRIBUTING.md) for details on our code of conduct and the process for submitting pull requests.

## Support

- **Issues**: [GitHub Issues](https://github.com/dzentota/router/issues)
- **Documentation**: [GitHub Wiki](https://github.com/dzentota/router/wiki)
- **Security**: webtota@gmail.com
