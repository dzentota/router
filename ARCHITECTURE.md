# Architecture

This document describes the architecture and design principles of the dzentota/router library.

## Overview

The dzentota/router is a high-performance, security-first PHP routing library that implements a comprehensive PSR-15 middleware suite. The architecture follows modern PHP best practices with a focus on security, performance, and maintainability.

## Core Architecture

### 1. Router Core

The router core implements a tree-based route matching algorithm for optimal performance:

```
Router
├── Route Registration   → returns fluent Route object
├── Route class          → where() / name() / defaults() / tag()
├── Route Tree Construction
├── Route Matching       → applies defaults for absent optional params
├── Parameter Validation (TypedValue parse)
├── URL Generation       → validates params against Typed constraints
├── resource() / apiResource() macros
├── UrlSigner            → HMAC-signed URLs with TTL
├── AttributeLoader      → PHP 8 #[RouteAttribute] scanning
├── Auto-naming          → pattern-derived names for single-method routes
└── Statistics / Tags    → getStats(), getRoutesByTag()
```

#### Route Object (Fluent API)

`addRoute()` (and all shortcut methods) return a `Route` instance.
The `Route` object is stored by reference in `rawRoutes`; mutations take
effect immediately:

```php
$router->get('/users/{id}', 'UserShow')
       ->where(['id' => UserId::class])   // validates constraint implements Typed
       ->name('users.show')               // registers in namedRoutes + reverse index
       ->defaults(['id' => 1])            // injected when optional param absent
       ->tag(['api', 'users']);            // for getRoutesByTag() / getStats()
```

`Route::name()` calls back to `Router::_registerRouteName()` so the named
routes index and the O(1) reverse index (`routeNameIndex`) stay consistent
at all times.

#### Route Tree Structure

Routes are organized in a tree structure for efficient matching:

```
/
├── api/
│   └── users/
│       ├── {id} (GET, POST)
│       └── (POST)
└── (GET)
```

#### Type-Safe Parameters

All route parameters must have type constraints using the TypedValue system:

```php
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
```

### 2. Middleware Architecture

The middleware system follows PSR-15 standards with a comprehensive security-first approach:

```
Request Flow:
┌─────────────────┐
│   HTTP Request  │
└─────────┬───────┘
          │
┌─────────▼───────┐
│  CORS Middleware│ ← Preflight handling
└─────────┬───────┘
          │
┌─────────▼───────┐
│  CSP Middleware │ ← Security headers
└─────────┬───────┘
          │
┌─────────▼───────┐
│Honeypot Middleware│ ← Bot detection
└─────────┬───────┘
          │
┌─────────▼───────┐
│ CSRF Middleware │ ← Token validation
└─────────┬───────┘
          │
┌─────────▼───────┐
│RouteMatch Middleware│ ← Route matching
└─────────┬───────┘
          │
┌─────────▼───────┐
│RouteDispatch Middleware│ ← Handler execution
└─────────┬───────┘
          │
┌─────────▼───────┐
│  HTTP Response  │
└─────────────────┘
```

## Security Middleware Suite

### 1. CSRF Protection (`CsrfMiddleware`)

**Architecture:**
- Strategy pattern for different protection approaches
- Cryptographically secure token generation
- HMAC-signed cookies for stateless protection
- PSR-16 cache integration for stateful token storage
- Optional per-IP failure rate limiting via PSR-16 cache

**Components:**
```
CsrfMiddleware
├── CsrfProtectionStrategyInterface
│   ├── SignedDoubleSubmitCookieStrategy  — stateless, HMAC-signed
│   └── SynchronizerTokenStrategy        — stateful, cache-backed
├── TokenGenerator                        — random_bytes() + hex encoding
└── (optional) PSR-16 CacheInterface      — failure rate limiting
```

**Rate-limiting flow:**
1. Look up failure count for the request IP in cache
2. If count ≥ threshold → immediately return 403 (no token checked)
3. On validation failure → increment count with TTL; return 403
4. On success → pass through; attach new token to response

### 2. Content Security Policy (`CspMiddleware`)

**Architecture:**
- Configurable policy directives
- Automatic nonce generation
- Report-only mode support
- Secure defaults for modern applications

**Features:**
- Nonce generation for inline scripts/styles
- Configurable policy directives
- Report URI support
- Automatic header injection

### 3. CORS Protection (`CorsMiddleware`)

**Architecture:**
- Full CORS policy implementation
- Preflight request handling
- **Origin format validation** (`filter_var(FILTER_VALIDATE_URL)`) before whitelist comparison — rejects syntactically malformed values
- Method and header validation

**Flow:**
1. Validate Origin header format; reject if malformed
2. Compare against explicit origin whitelist
3. Handle preflight (OPTIONS) with appropriate headers
4. Method and header validation
5. Credential handling with security considerations

### 4. Honeypot Protection (`HoneypotMiddleware`)

**Architecture:**
- Hidden field detection
- Timing analysis for bot detection
- Rate limiting with exponential backoff
- Comprehensive logging

**Components:**
```
HoneypotMiddleware
├── Field Detection
├── Timing Analysis
├── Rate Limiting
└── Logging
```

## Route Processing

### 1. Route Matching (`RouteMatchMiddleware`)

**Responsibilities:**
- Match incoming requests to defined routes
- Extract and validate route parameters
- Add route information to request attributes
- Handle 404 and 405 responses

**Named-route lookup (O(1)):**  
`Router` maintains a `$routeNameIndex` reverse map (`route_pattern → name`) populated at registration. `RouteMatchMiddleware` calls `Router::findNameForRoute()` for a direct hash lookup instead of iterating all named routes.

**Flow:**
1. Parse request method and URI
2. Traverse route tree for matching
3. Validate parameters against type constraints
4. Look up route name via reverse index
5. Add route data to request attributes

### 2. Route Dispatch (`RouteDispatchMiddleware`)

**Responsibilities:**
- Execute matched route handlers
- Support multiple handler types (closures, controllers, invokables)
- Dependency injection integration
- Error handling and response creation

**Handler Types:**
- Closures and callables
- `Controller@method` strings
- Invokable controllers
- Array-based `[class, method]` handlers

**Reflection caching:**  
`ReflectionFunctionAbstract` instances are cached in `$reflectionCache` keyed by handler identity (class + method name, or closure object ID). Subsequent requests reuse the cached reflection, avoiding repeated introspection per request.

## Middleware Stack

### Implementation

The middleware stack uses an immutable index-based approach for optimal performance:

```php
class MiddlewareStack implements RequestHandlerInterface
{
    private array $middlewares = [];
    private RequestHandlerInterface $finalHandler;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->processMiddleware($request, 0);
    }

    protected function processMiddleware(ServerRequestInterface $request, int $index): ResponseInterface
    {
        if ($index >= count($this->middlewares)) {
            return $this->finalHandler->handle($request);
        }

        $middleware = $this->middlewares[$index];
        $nextHandler = new class($this, $index + 1) implements RequestHandlerInterface {
            // Next handler implementation
        };

        return $middleware->process($request, $nextHandler);
    }
}
```

### Ordering Strategy

Security middleware is ordered for optimal protection:

1. **CORS** - Handle preflight requests first
2. **CSP** - Add security headers early
3. **Honeypot** - Detect bots before processing
4. **CSRF** - Validate tokens before route matching
5. **Route Match** - Match routes after security checks
6. **Route Dispatch** - Execute handlers last

## Performance Optimizations

### 1. Route Tree Caching

Routes are compiled into a tree structure that can be cached:

```php
// Generate cache
$cacheData = $router->dump();
file_put_contents('routes.cache', serialize($cacheData));

// Load cache
$router->load(unserialize(file_get_contents('routes.cache')));
```

### 2. Type Validation Optimization

- Early validation failure at route registration (`parseUri()` called eagerly in `addRoute()`)
- `Route::where()` validates constraint classes at call time, not at first request
- Validated `Typed` objects carried as request attributes — no re-validation downstream
- Possessive quantifiers (`[^?}]++`) in `generateUrl()` regex prevent ReDoS

### 3. Named Route Index

`routeNameIndex` is a reverse map: `pattern → name`, maintained in O(1) by
`Route::name()` calling `Router::_registerRouteName()`. `RouteMatchMiddleware`
looks up the route name in O(1) instead of scanning the entire named-routes map.

### 4. Reflection Caching

`RouteDispatchMiddleware` caches `ReflectionFunctionAbstract` instances keyed by
handler identity, avoiding repeated introspection per request.

### 5. Middleware Stack Optimization

- Immutable middleware execution — no array mutations during request processing
- Efficient handler chaining via index-based recursion

## Error Handling

### Exception Hierarchy

```
\RuntimeException
└── RouterException                 (src/Exception/RouterException.php)
    ├── InvalidRouteException       — bad pattern, path traversal, unknown method
    └── InvalidConstraintException  — parameter has no or invalid constraint class

\Exception
├── NotFoundException               — no route matched (→ 404)
└── MethodNotAllowedException       — method not allowed (→ 405)

\Exception (Middleware)
├── CsrfException                   — token validation failure
└── SecurityException               — general security violation
```

`InvalidRouteException` is thrown **at route registration time** (not at first request), so misconfigured routes are caught during application bootstrap.

### Error Response Strategy

- Safe error messages that don't leak sensitive information
- Appropriate HTTP status codes
- Structured error responses
- Comprehensive logging

## Security Design Principles

### 1. Defense in Depth

Multiple layers of security protection:
- Input validation at the router level
- Security middleware for common attacks
- Type safety throughout the application
- Comprehensive error handling

### 2. Fail-Safe Defaults

- All route parameters require type constraints
- Security middleware enabled by default
- Strict CORS policies
- Secure cookie configurations

### 3. Principle of Least Privilege

- Minimal required permissions
- Explicit route definitions only
- No dynamic code execution
- Controlled parameter access

## Integration Patterns

### 1. PSR Standards Compliance

- **PSR-7**: HTTP Message Interfaces
- **PSR-15**: HTTP Server Request Handlers
- **PSR-16**: Simple Cache Interface
- **PSR-11**: Container Interface (optional)
- **PSR-3**: Logger Interface

### 2. Framework Integration

The router can be integrated into any PSR-7/PSR-15 compatible framework:

```php
// Laravel integration example
class RouterServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(Router::class, function ($app) {
            $router = new Router();
            // Configure routes
            return $router;
        });
    }
}
```

### 3. Dependency Injection

Support for PSR-11 containers:

```php
$routeDispatchMiddleware = new RouteDispatchMiddleware($container);
```

## Testing Architecture

### 1. Unit Testing

- Isolated component testing
- Mock-based testing for dependencies
- Comprehensive coverage requirements
- Security-focused test cases

### 2. Integration Testing

- End-to-end middleware testing
- Security scenario testing
- Performance benchmarking
- Error condition testing

### 3. Security Testing

- CSRF attack simulation
- XSS prevention testing
- CORS policy validation
- Honeypot effectiveness testing

## Deployment Considerations

### 1. Production Configuration

- Environment-based configuration
- Secure secret management
- Performance monitoring
- Error logging and alerting

### 2. Caching Strategy

- Route tree caching
- Type validation caching
- Security token caching
- Response caching considerations

### 3. Monitoring and Logging

- Security event logging
- Performance metrics
- Error tracking
- Audit trail maintenance

## Future Architecture

### Planned Enhancements

1. **GraphQL Support**: Native GraphQL route handling
2. **WebSocket Integration**: Real-time communication support
3. **Rate Limiting**: Advanced rate limiting middleware
4. **API Versioning**: Built-in API versioning support
5. **Metrics Collection**: Performance and security metrics

### Extension Points

The architecture provides extension points for:
- Custom security strategies
- Additional middleware components
- Custom type validators
- Framework-specific integrations

## Conclusion

The dzentota/router architecture provides a solid foundation for building secure, high-performance PHP applications. The comprehensive middleware suite, type-safe routing, and security-first design make it suitable for production use in security-critical environments.

The modular design allows for easy extension and customization while maintaining the core security and performance guarantees that make the library reliable for enterprise applications. 