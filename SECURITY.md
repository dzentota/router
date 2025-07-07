# Security Policy

## Overview

The dzentota Router is designed with security as a fundamental principle, following the [AppSec Manifesto](https://github.com/dzentota/AppSecManifesto) guidelines. This document outlines our security approach, threat model, and best practices for secure usage.

## Supported Versions

We provide security updates for the following versions:

| Version | Supported          |
| ------- | ------------------ |
| 1.x.x   | :white_check_mark: |
| < 1.0   | :x:                |

## Security Principles

### 1. Parse, Don't Validate

The router follows the principle of **parsing input into strongly-typed domain objects** rather than just validating strings. This approach:

- Eliminates entire classes of vulnerabilities
- Provides type safety throughout the application
- Makes invalid states unrepresentable

```php
// Secure: Parse into typed object
$router->get('/users/{id}', 'UserController@show', ['id' => Id::class]);

// Insecure: Accept raw strings
$router->get('/users/{id}', 'UserController@show'); // No constraints
```

### 2. Minimized Attack Surface

Following the **Absolute Zero** principle:

- All route parameters MUST have type constraints
- No dynamic code execution
- Explicit route definitions only
- Fail-hard on invalid input

### 3. Secure by Default

- Routes without constraints are rejected
- Invalid parameters throw exceptions immediately
- URL generation validates parameters before inclusion
- No automatic type coercion

## Threat Model

### In Scope

1. **Route Parameter Injection**: Malicious parameters in URLs
2. **URL Generation Attacks**: Injection through generated URLs
3. **Route Pollution**: Malicious route definitions
4. **Type Confusion**: Bypassing type constraints
5. **Denial of Service**: Resource exhaustion through route matching
6. **Information Disclosure**: Leaking sensitive data through errors

### Out of Scope

1. **General PHP vulnerabilities**: We rely on PHP's security
2. **Application logic**: Security of controllers and business logic
3. **Infrastructure**: Server, network, and deployment security
4. **Third-party dependencies**: Security of external libraries

## Security Features

### Type-Safe Route Parameters

All route parameters must be validated using TypedValue constraints:

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
        
        // Validate format
        if (!is_numeric($value)) {
            $result->addError('User ID must be numeric');
            return $result;
        }
        
        // Validate range
        $id = (int)$value;
        if ($id <= 0 || $id > PHP_INT_MAX) {
            $result->addError('User ID out of valid range');
        }
        
        return $result;
    }
}

// Usage
$router->get('/users/{id}', 'UserController@show', ['id' => UserId::class]);
```

### Secure URL Generation

The `generateUrl()` method provides secure URL generation with parameter validation:

```php
// Parameters are validated against constraints before URL generation
try {
    $url = $router->generateUrl('users.show', ['id' => '123']);
} catch (\Exception $e) {
    // Handle invalid parameter
}
```

### Exception Safety

Error messages are designed to be safe for display while providing useful debugging information:

```php
// Safe error messages that don't leak sensitive information
throw new \Exception("Named route 'users.show' not found");
throw new \Exception("Parameter 'id' does not match constraint for route 'users.show'");
```

## Security Best Practices

### For Developers

#### 1. Always Use Type Constraints

```php
// Secure
$router->get('/posts/{slug}', 'PostController@show', ['slug' => Slug::class]);

// Insecure - will throw exception
$router->get('/posts/{slug}', 'PostController@show'); // Missing constraints
```

#### 2. Implement Robust Type Validation

```php
class Slug implements Typed
{
    use TypedValue;

    public static function validate($value): ValidationResult
    {
        $result = new ValidationResult();
        
        // Check format
        if (!preg_match('/^[a-z0-9-]+$/', $value)) {
            $result->addError('Invalid slug format');
        }
        
        // Check length
        if (strlen($value) > 100) {
            $result->addError('Slug too long');
        }
        
        return $result;
    }
}
```

#### 3. Secure URL Generation

```php
// Validate parameters before generating URLs
if ($router->hasRoute('users.show')) {
    try {
        $url = $router->generateUrl('users.show', ['id' => $userId]);
    } catch (\Exception $e) {
        // Handle error appropriately
        $logger->warning('Invalid parameter for URL generation', [
            'route' => 'users.show',
            'parameter' => $userId,
            'error' => $e->getMessage()
        ]);
    }
}
```

#### 4. Route Caching Security

When using route caching, ensure cache files are protected:

```php
// Generate cache with proper permissions
$cacheData = $router->dump();
$cacheFile = '/secure/path/routes.cache';

// Write with restricted permissions
file_put_contents($cacheFile, serialize($cacheData), LOCK_EX);
chmod($cacheFile, 0640); // Read-write for owner, read for group
```

### For Applications

#### Input Sanitization

While the router handles route parameter validation, applications should implement additional input sanitization:

```php
class SecureController
{
    public function show(Request $request, string $id): Response
    {
        // $id is already validated by router constraints
        // But validate any additional request data
        
        $params = $request->getQueryParams();
        $sortBy = $this->validateSortParameter($params['sort'] ?? 'id');
        
        // Use the validated data
        return $this->userService->getUser($id, $sortBy);
    }
}
```

#### Error Handling

Handle routing exceptions appropriately:

```php
try {
    $route = $router->findRoute($method, $uri);
} catch (NotFoundException $e) {
    // Log the attempt
    $logger->info('Route not found', ['uri' => $uri, 'method' => $method]);
    
    // Return safe 404 response
    return new NotFoundResponse();
} catch (MethodNotAllowedException $e) {
    // Log the attempt
    $logger->info('Method not allowed', [
        'uri' => $uri, 
        'method' => $method,
        'allowed' => $e->getAllowedMethods()
    ]);
    
    // Return safe 405 response
    return new MethodNotAllowedResponse($e->getAllowedMethods());
}
```

## Security Testing

### Automated Tests

Run security-focused tests regularly:

```bash
# Run security test group
./vendor/bin/phpunit --group security

# Run with coverage to ensure all security paths are tested
./vendor/bin/phpunit --coverage-html coverage/
```

### Fuzzing

Test route parameter validation with fuzzing:

```php
public function testParameterFuzzing(): void
{
    $router = new Router();
    $router->get('/users/{id}', 'UserController@show', ['id' => UserId::class]);
    
    $maliciousInputs = [
        '../../../etc/passwd',
        '<script>alert("xss")</script>',
        'union select * from users',
        str_repeat('a', 10000),
        "\x00\x01\x02",
        '<?php system($_GET[\'cmd\']); ?>',
    ];
    
    foreach ($maliciousInputs as $input) {
        $this->expectException(\Exception::class);
        $router->findRoute('GET', "/users/{$input}");
    }
}
```

### Performance Testing

Ensure route matching performance doesn't degrade with malicious input:

```php
public function testRouteMatchingPerformance(): void
{
    $router = new Router();
    // Add many routes...
    
    $start = microtime(true);
    
    // Test with deep nested paths
    try {
        $router->findRoute('GET', '/' . str_repeat('a/', 1000));
    } catch (NotFoundException $e) {
        // Expected
    }
    
    $elapsed = microtime(true) - $start;
    $this->assertLessThan(0.1, $elapsed, 'Route matching should be fast even with malicious input');
}
```

## Reporting Security Vulnerabilities

### Responsible Disclosure

We encourage responsible disclosure of security vulnerabilities. **Please do not report security vulnerabilities through public GitHub issues.**

### How to Report

1. **Email**: Send details to `security@dzentota.com`
2. **Subject**: Include "SECURITY:" prefix
3. **Content**: Include:
   - Detailed description of the vulnerability
   - Steps to reproduce
   - Potential impact assessment
   - Suggested fix (if available)

### Response Process

1. **Acknowledgment**: Within 48 hours
2. **Investigation**: Security team will investigate
3. **Fix Development**: Develop and test fix
4. **Disclosure**: Coordinate disclosure timeline
5. **Release**: Security update release
6. **Credit**: Public credit to reporter (if desired)

### Timeline

- **Critical**: 24-48 hours
- **High**: 7 days
- **Medium**: 30 days
- **Low**: 90 days

## Security Updates

### Notification

Subscribe to security notifications:
- Watch this repository for releases
- Follow [@dzentota](https://twitter.com/dzentota) for announcements
- Join our security mailing list: `security-announce@dzentota.com`

### Update Process

1. **Assessment**: Evaluate security impact
2. **Testing**: Test updates in development environment
3. **Deployment**: Deploy updates promptly
4. **Verification**: Verify fixes are working

## Security Checklist

### For Route Definition

- [ ] All route parameters have type constraints
- [ ] Type constraints implement proper validation
- [ ] Named routes use descriptive, non-sensitive names
- [ ] Route groups are properly structured

### For URL Generation

- [ ] Parameters are validated before generating URLs
- [ ] Generated URLs are properly escaped in templates
- [ ] Error handling doesn't leak sensitive information

### For Production Deployment

- [ ] Route cache is properly secured
- [ ] Error reporting is configured appropriately
- [ ] Logging includes security-relevant events
- [ ] Performance monitoring is in place

## Additional Resources

- [AppSec Manifesto](https://github.com/dzentota/AppSecManifesto)
- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [PHP Security Best Practices](https://www.php.net/manual/en/security.php)
- [Composer Security Advisories](https://github.com/advisories)

## License

This security policy is licensed under [MIT License](LICENSE).

---

Security is a shared responsibility. By following these guidelines and best practices, we can work together to build secure applications with dzentota Router. 