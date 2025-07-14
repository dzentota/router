# Architecture

This document describes the architecture and design principles of the dzentota/router library.

## Overview

The dzentota/router is a high-performance, security-first PHP routing library that implements a comprehensive PSR-15 middleware suite. The architecture follows modern PHP best practices with a focus on security, performance, and maintainability.

## Core Architecture

### 1. Router Core

The router core implements a tree-based route matching algorithm for optimal performance:

```
Router
├── Route Registration
├── Route Tree Construction
├── Route Matching
├── Parameter Validation
└── URL Generation
```

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
- PSR-16 cache integration for stateful protection

**Components:**
```
CsrfMiddleware
├── CsrfProtectionStrategyInterface
├── SignedDoubleSubmitCookieStrategy
├── SynchronizerTokenStrategy
├── TokenGenerator
└── TokenStorageInterface
```

**Flow:**
1. Token generation using cryptographically secure random bytes
2. HMAC signing for stateless validation
3. Cookie-based token distribution
4. Request validation with timing attack protection

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
- Origin validation with security-first defaults
- Method and header validation

**Flow:**
1. Origin validation against allowed origins
2. Preflight request handling for complex requests
3. Method and header validation
4. Credential handling with security considerations

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

**Flow:**
1. Parse request method and URI
2. Traverse route tree for matching
3. Validate parameters against type constraints
4. Add route data to request attributes

### 2. Route Dispatch (`RouteDispatchMiddleware`)

**Responsibilities:**
- Execute matched route handlers
- Support multiple handler types (closures, controllers, invokables)
- Dependency injection integration
- Error handling and response creation

**Handler Types:**
- Closures and callables
- Controller@method strings
- Invokable controllers
- Array-based handlers

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

Type validation uses optimized algorithms:
- Early validation failure
- Cached validation results
- Minimal memory allocation

### 3. Middleware Stack Optimization

- Immutable middleware execution
- No array mutations during request processing
- Efficient handler chaining

## Error Handling

### Exception Hierarchy

```
RouterException
├── NotFoundException
├── MethodNotAllowedException
└── SecurityException
    ├── CsrfException
    └── SecurityException
```

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