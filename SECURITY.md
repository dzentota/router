# Security Policy

## Security-First Design Philosophy

The dzentota/router library is built with a security-first approach, implementing comprehensive protective measures against common web application vulnerabilities. Our design philosophy aligns with the principles outlined in the [AppSecManifesto](https://github.com/dzentota/AppSecManifesto).

## Security Features

### CSRF Protection

The router includes multiple CSRF protection strategies:

#### SignedDoubleSubmitCookieStrategy

This stateless implementation uses cryptographically signed cookies with HMAC verification:

```php
use dzentota\Router\Middleware\CsrfMiddleware;
use dzentota\Router\Middleware\Security\SignedDoubleSubmitCookieStrategy;
use dzentota\Router\Middleware\Security\TokenGenerator;

$tokenGenerator = new TokenGenerator();
$cookieOptions = [
    'secure' => true,      // Only send over HTTPS
    'httponly' => true,    // Not accessible via JavaScript
    'samesite' => 'Lax',   // Prevent CSRF in most contexts
    'path' => '/'          // Site-wide cookie
];

$csrfStrategy = new SignedDoubleSubmitCookieStrategy(
    $tokenGenerator,
    'your-secure-signing-key',
    '__Host-csrf-token',   // Cookie name with __Host- prefix
    $cookieOptions
);

$csrfMiddleware = new CsrfMiddleware($csrfStrategy);
```

#### SynchronizerTokenStrategy

This stateful implementation stores tokens in a PSR-16 compatible cache:

```php
use dzentota\Router\Middleware\CsrfMiddleware;
use dzentota\Router\Middleware\Security\SynchronizerTokenStrategy;
use dzentota\Router\Middleware\Security\TokenGenerator;
use dzentota\Router\Middleware\Cache\ArrayCache;

$tokenGenerator = new TokenGenerator();
$tokenStorage = new ArrayCache();

$csrfStrategy = new SynchronizerTokenStrategy(
    $tokenGenerator,
    $tokenStorage,
    3600 // Token lifetime in seconds
);

$csrfMiddleware = new CsrfMiddleware($csrfStrategy);
```

### Content Security Policy (CSP)

The CspMiddleware implements comprehensive Content Security Policy headers with nonce support:

```php
use dzentota\Router\Middleware\CspMiddleware;
use dzentota\Router\Middleware\Security\TokenGenerator;

$tokenGenerator = new TokenGenerator();
$cspPolicy = [
    'default-src' => ["'self'"],
    'script-src' => ["'self'", 'https://cdn.jsdelivr.net'],
    'style-src' => ["'self'", 'https://fonts.googleapis.com'],
    'img-src' => ["'self'", 'data:'],
    'font-src' => ["'self'", 'https://fonts.gstatic.com'],
    'object-src' => ["'none'"],
    'frame-ancestors' => ["'none'"],
    'form-action' => ["'self'"],
    'base-uri' => ["'self'"]
];

$reportOnly = false; // Set to true for testing without enforcement
$reportUri = 'https://example.com/csp-report'; // Optional URI for violation reporting
$addNonce = true; // Generate and append nonces for inline scripts/styles

$cspMiddleware = new CspMiddleware($cspPolicy, $reportOnly, $reportUri, $addNonce, $tokenGenerator);
```

### Cross-Origin Resource Sharing (CORS)

The CorsMiddleware implements RFC-compliant CORS headers with secure defaults:

```php
use dzentota\Router\Middleware\CorsMiddleware;

$corsOptions = [
    'allowed_origins' => ['https://trusted-app.example.com'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
    'allowed_headers' => ['Content-Type', 'Authorization', 'X-CSRF-TOKEN'],
    'exposed_headers' => ['X-Rate-Limit'],
    'allow_credentials' => true,
    'max_age' => 86400, // 24 hours
    'require_exact_origin' => true // Strict origin checking
];

$corsMiddleware = new CorsMiddleware($corsOptions);
```

### Bot Detection and Rate Limiting

The HoneypotMiddleware implements multiple bot detection mechanisms:

```php
use dzentota\Router\Middleware\HoneypotMiddleware;

// Field names that will be hidden via CSS
$honeypotFields = ['website', 'url', 'email_confirm'];

// Minimum expected submission time in seconds
$minTime = 3;

// Apply rate limiting when bot detected
$applyRateLimiting = true;

// Custom rate limit storage implementation
$rateLimitStorage = new CustomRateLimitStorage();

$honeypotMiddleware = new HoneypotMiddleware(
    $honeypotFields,
    $minTime,
    $applyRateLimiting,
    $rateLimitStorage
);
```

## Type Safety for Parameters

The router enforces strong type validation for all route parameters:

```php
use dzentota\TypedValue\Typed;
use dzentota\TypedValue\TypedValue;
use dzentota\TypedValue\ValidationResult;

class UserId implements Typed
{
    use TypedValue;

    public static function validate($value): ValidationResult
    {
        $result = new ValidationResult();
        if (!is_numeric($value) || intval($value) <= 0 || strval(intval($value)) !== strval($value)) {
            $result->addError('Invalid user ID format');
        }
        return $result;
    }
    
    public function toNative(): int
    {
        return (int)$this->value;
    }
}

// Using typed parameters in routes
$router->get('/users/{id}', 'UserController@show', ['id' => UserId::class]);
```

## Security Recommendations

### 1. Always Use HTTPS

Configure your web server to enforce HTTPS with proper certificate configuration and implement HSTS:

```php
// Example for setting HSTS header
header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
```

### 2. Proper Cookie Configuration

Always set secure cookie attributes:

```php
$cookieOptions = [
    'secure' => true,      // Only send over HTTPS
    'httponly' => true,    // Not accessible via JavaScript
    'samesite' => 'Lax',   // Prevent CSRF in most contexts
    'path' => '/',         // Site-wide cookie
    'domain' => null,      // Current domain only
    'expires' => 0         // Session cookie
];
```

### 3. Implement Defense in Depth

Always use multiple security layers by combining middleware:

```php
$handler = MiddlewareStack::create(
    $finalHandler,
    $corsMiddleware,         // First handle CORS
    $cspMiddleware,          // Then apply CSP headers
    $csrfMiddleware,         // Protect against CSRF
    $honeypotMiddleware,     // Bot/spam protection
    $routeMatchMiddleware,   // Then match routes
    $routeDispatchMiddleware // Finally dispatch to handler
);
```

### 4. Rate Limiting

Implement rate limiting for authentication endpoints and APIs:

```php
use dzentota\Router\Middleware\Cache\InMemoryRateLimitStorage;

$rateLimitStorage = new InMemoryRateLimitStorage();
// Or implement your own storage with database persistence
```

### 5. Input Validation

Always validate and sanitize all input parameters:

```php
// Define strict type validation for all route parameters
$router->get('/posts/{slug}', 'PostController@show', [
    'slug' => SlugValue::class
]);
```

### 6. Disable Detailed Errors in Production

Configure your application to log errors but not display them to users in production:

```php
if (APP_ENV === 'production') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', '/path/to/error.log');
}
```

## Vulnerability Reporting

We take security issues seriously. Please do not report security vulnerabilities using public GitHub issues.

Instead, please report them directly to **webtota@gmail.com**. If possible, encrypt your message using our PGP key.

Please include the following information:

1. Type of issue
2. Full path of the affected file(s)
3. Location of the affected code
4. Any special configuration required to reproduce the issue
5. Step-by-step instructions to reproduce the issue
6. Proof-of-concept or exploit code (if possible)
7. Impact of the issue

We will acknowledge receipt of your vulnerability report as soon as possible and send you regular updates about our progress.

## Security Updates

Security updates will be released as soon as possible after discovering or receiving notice of a vulnerability.

Updates will be published as new releases on GitHub and Packagist with clear descriptions of the fixed vulnerabilities without disclosing exploitation details.

## Credit

We are happy to acknowledge security researchers who responsibly disclose vulnerabilities to us.
