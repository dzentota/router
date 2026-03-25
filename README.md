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
use dzentota\Router\Middleware\Cache\ArrayCache;

$router = new Router();

// Add your routes here...

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

// Enable CSRF rate limiting — blocks an IP after 5 failures within 1 hour.
$csrfMiddleware = new CsrfMiddleware(
    strategy:             $csrfStrategy,
    cache:                new ArrayCache(), // swap for Redis/Memcached in production
    maxFailedAttempts:    5,
    failureWindowSeconds: 3600,
);

$middlewareStack = MiddlewareStack::create(
    $finalHandler,
    new CorsMiddleware([
        'allowed_origins'      => ['https://app.example.com'],
        'allowed_methods'      => ['GET', 'POST', 'PUT', 'DELETE'],
        'allow_credentials'    => true,
        'require_exact_origin' => true,
    ]),
    $cspMiddleware,
    $honeypotMiddleware,
    $csrfMiddleware,
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

### Named Routes — positional or fluent

```php
// Legacy positional argument (still supported)
$router->get('/users/{id}', 'UserController@show', ['id' => UserId::class], 'users.show');

// Fluent API — recommended
$router->get('/users/{id}', 'UserController@show')
       ->where(['id' => UserId::class])
       ->name('users.show');

// Generate URL
$url = $router->generateUrl('users.show', ['id' => 123]);
```

### Route Groups
```php
$router->addGroup('/api/v1', function(Router $router) {
    $router->get('/users', 'UserController@index');
    $router->post('/users', 'UserController@store');
    $router->get('/users/{id}', 'UserController@show')
           ->where(['id' => UserId::class]);
});
```

### Fluent route metadata

After `addRoute()` / any shortcut method returns a `Route` object.  Chain as many
methods as needed before registering the next route:

```php
$router->get('/reports/{id}', 'ReportShow')
       ->where(['id' => ReportId::class])
       ->name('reports.show')
       ->defaults(['id' => 1])   // used when optional segment is absent
       ->tag(['api', 'reports']);
```

| Method            | Purpose                                             |
|-------------------|-----------------------------------------------------|
| `->where([…])`    | Set Typed constraints (validates class at call time) |
| `->name(string)`  | Assign/rename a route and update the reverse index  |
| `->defaults([…])` | Raw defaults for optional params absent from URI    |
| `->tag(string\|array)` | Tag the route for filtering / docs              |

### Resource macros

Generate a full set of conventional RESTful routes in one call:

```php
// Full resource (7 routes)
$router->resource('/posts', PostController::class, ['id' => PostId::class]);
//   GET    /posts              → PostController::index    (posts.index)
//   GET    /posts/create       → PostController::create   (posts.create)
//   POST   /posts              → PostController::store    (posts.store)
//   GET    /posts/{id}         → PostController::show     (posts.show)
//   GET    /posts/{id}/edit    → PostController::edit     (posts.edit)
//   PUT|PATCH /posts/{id}      → PostController::update   (posts.update)
//   DELETE /posts/{id}         → PostController::destroy  (posts.destroy)

// API resource — omits create/edit HTML-form routes (5 routes)
$router->apiResource('/api/comments', CommentController::class, ['id' => CommentId::class]);
```

Resources inside groups pick up the group prefix for both the URI and the route name:

```php
$router->addGroup('/admin', function (Router $r) {
    $r->resource('/users', AdminUserController::class, ['id' => UserId::class]);
    // routes named: admin.users.index, admin.users.show, …
});
```

### PHP 8 Attribute-based routing

Decorate controllers with `#[RouteAttribute]` and load them with `AttributeLoader`:

```php
use dzentota\Router\Attribute\RouteAttribute;

#[RouteAttribute('/api/v1')]   // class-level prefix
class UserController
{
    #[RouteAttribute('/users', methods: 'GET', name: 'users.index', tags: ['api'])]
    public function index(): ResponseInterface { … }

    #[RouteAttribute('/users/{id}', methods: 'GET', constraints: ['id' => UserId::class],
                     name: 'users.show')]
    public function show(UserId $id): ResponseInterface { … }

    #[RouteAttribute('/users', methods: 'POST', name: 'users.store')]
    public function store(): ResponseInterface { … }
}

// Load a single class
(new AttributeLoader($router))->loadFromClass(UserController::class);

// Or scan an entire directory
(new AttributeLoader($router))->loadFromDirectory(__DIR__ . '/Controllers');
```

### Handler format

The router accepts any of the following handler formats:

| Format                          | Example                              |
|---------------------------------|--------------------------------------|
| Closure / callable              | `fn($req) => …`                      |
| `Class@method`                  | `'UserController@show'`              |
| `Class::method`                 | `'UserController::show'`             |
| Invokable class string          | `'InvokableHandler'`                 |
| Array `[class, method]`         | `[UserController::class, 'show']`    |

### Auto-naming

Enable auto-name generation for routes that have no explicit name.
Generated names follow the pattern `{path-segments}.{method}`:

```php
$router->enableAutoNaming();

$router->get('/admin/users/{id}', 'AdminUserShow', ['id' => UserId::class]);
// auto-name: 'admin.users.id.get'

$router->get('/profile', 'Profile');
// auto-name: 'profile.get'

// Explicit names always win
$router->get('/login', 'Login', [], 'auth.login');
// name remains 'auth.login', NOT 'login.get'
```

### Route tags and stats

```php
$router->get('/api/users', 'UserIndex')->tag(['api', 'public']);
$router->get('/admin/users', 'AdminIndex')->tag('admin');

// Find all routes carrying a tag
$apiRoutes = $router->getRoutesByTag('api');

// Aggregate statistics
$stats = $router->getStats();
// [
//   'total'   => 2,
//   'named'   => 0,
//   'tagged'  => 2,
//   'methods' => ['GET' => 2],
//   'tags'    => ['api' => 1, 'public' => 1, 'admin' => 1],
// ]
```

### Signed URLs

Generate tamper-proof, time-limited URLs using HMAC-SHA256:

```php
use dzentota\Router\UrlSigner;

$signer = new UrlSigner($router, $_ENV['APP_KEY'], defaultTtl: 3600);

// Sign — adds ?expires=…&signature=… to the route URL
$signedUrl = $signer->sign('invoices.download', ['id' => '42']);
// → /invoices/42/download?expires=1720000000&signature=<hmac>

// Verify — returns false if expired or tampered
if (!$signer->verify($signedUrl)) {
    // respond 403 or 410
}
```

Security notes for signed URLs:
- Store `APP_KEY` in an environment variable; never commit it.
- Minimum key length is 16 characters; 32+ random bytes recommended.
- Expiry is checked before the HMAC to avoid timing oracles on expired links.
- Signature comparison uses `hash_equals()` (constant-time).



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
    ->withNonce(true)
    ->reportOnly(false)
    ->build();
```

> **⚠️ Unsafe directives require explicit confirmation.**  
> Calling `allowInlineScripts()`, `allowInlineStyles()`, or `allowEval()` without
> passing `true` throws an `InvalidArgumentException`. This prevents accidental
> weakening of your CSP policy:
>
> ```php
> // Throws InvalidArgumentException — explicit confirmation required
> ->allowInlineScripts()
>
> // Correct — acknowledges the security trade-off
> ->allowInlineScripts(true)
> ```

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

## Simple Dispatch API

`dispatch()` is the recommended single entry point for most applications. It automatically
builds and executes the full middleware + routing pipeline — no need to manually assemble
`MiddlewareStack::create()`.

### Middleware levels

Register middleware at three levels:

```php
use dzentota\Router\Router;
use dzentota\Router\Middleware\CorsMiddleware;
use dzentota\Router\Middleware\CsrfMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

$router = new Router(); // optionally: new Router($container, $logger)

// 1. Global middleware — runs for EVERY request
$router->middleware(new CorsMiddleware([
    'allowed_origins' => ['https://app.example.com'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE'],
    'allow_credentials' => true,
]));
$router->middleware(new CsrfMiddleware(strategy: $csrfStrategy, cache: $cache));

// 2. Group middleware — runs for every route inside the group
$router->addGroup('/admin', function (Router $r): void {

    // 3. Per-route middleware — runs only for this specific route
    $r->get('/dashboard', 'AdminController@dashboard')
      ->middleware(new class implements MiddlewareInterface {
          public function process(ServerRequestInterface $req, RequestHandlerInterface $next): ResponseInterface
          {
              // e.g. verify dashboard-specific permission
              return $next->handle($req);
          }
      });

    $r->get('/users', 'AdminController@users');

}, [new class implements MiddlewareInterface {
    public function process(ServerRequestInterface $req, RequestHandlerInterface $next): ResponseInterface
    {
        // e.g. admin auth gate
        return $next->handle($req);
    }
}]);

$router->get('/public', 'HomeController@index');
```

### Single entry point

```php
// Execution order: global → group → per-route → handler
$response = $router->dispatch($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
```

You may also pass a pre-built PSR-7 request as the optional third argument:

```php
$response = $router->dispatch('/admin/dashboard', 'GET', $psrRequest);
```

### Advanced use

`MiddlewareStack::create()` remains fully supported for scenarios where you need
explicit control over the pipeline (e.g. custom final handlers or complex ordering):

```php
use dzentota\Router\Middleware\MiddlewareStack;
use dzentota\Router\Middleware\RouteMatchMiddleware;
use dzentota\Router\Middleware\RouteDispatchMiddleware;

$stack = MiddlewareStack::create(
    $finalHandler,
    new CorsMiddleware([...]),
    new CsrfMiddleware(...),
    new RouteMatchMiddleware($router),
    new RouteDispatchMiddleware($container, $logger)
);

$response = $stack->handle($request);
```

## Error Handling

The router uses a typed exception hierarchy so callers can handle errors precisely:

```php
use dzentota\Router\Exception\RouterException;
use dzentota\Router\Exception\InvalidRouteException;
use dzentota\Router\Exception\InvalidConstraintException;
use dzentota\Router\Exception\NotFoundException;
use dzentota\Router\Exception\MethodNotAllowedException;

// Route registration — throws InvalidRouteException for bad definitions
try {
    $router->get('/users/{id}/posts/{id}', 'handler', ['id' => UserId::class]); // duplicate param
    $router->get('/admin/../secret', 'handler');                                  // path traversal
} catch (InvalidRouteException $e) {
    // Bad route pattern caught immediately at registration time
}

// Route matching — throws NotFoundException / MethodNotAllowedException
try {
    $route = $router->findRoute($method, $uri);
} catch (NotFoundException $e) {
    // 404
} catch (MethodNotAllowedException $e) {
    // 405 — $e->getAllowedMethods() returns the permitted methods
}
```

## Performance

### Route Caching

Use `exportCache()` and `importCache()` for file-based caching. These methods use
**JSON** — never PHP's `serialize()` — making them immune to PHP Object Injection.

```php
// On deploy / warm-up: export routes to a JSON cache file
file_put_contents('routes.cache.json', $router->exportCache());
```

```php
// On every request: load from cache instead of re-registering routes
if (file_exists('routes.cache.json')) {
    $router->importCache(file_get_contents('routes.cache.json'));
} else {
    // Register routes normally
    $router->get('/users/{id}', 'UserController@show')->where(['id' => UserId::class]);
    // ...
}
```

> **Requirements:**
> - Handlers must be strings (`'Controller@method'`, `'Controller::method'`) or
>   `[ClassName, method]` arrays. Closure handlers throw a `\LogicException` on
>   `exportCache()`.
> - Per-route and group **middleware** (PHP objects) are not cached. Re-attach them
>   after `importCache()` using `Route::middleware()` or `addGroup()`.

> **⚠️ Security warning — `dump()` / `load()`:**
> These in-process methods return/accept a live PHP array containing objects and
> closures. **Never** pass this data through PHP's `serialize()` / `unserialize()`.
> Deserialising user-controlled data with `unserialize()` enables
> [PHP Object Injection](https://owasp.org/www-community/vulnerabilities/PHP_Object_Injection).
> Use `exportCache()` / `importCache()` for any file or network transfer.

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
