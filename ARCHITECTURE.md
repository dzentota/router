# Architecture

## Overview

The dzentota Router is a fast and flexible security-aware routing library designed with security as a primary concern. It follows the principle that **there is no sense in accepting data from the user (via HTTP) without properly validating it against your domain**.

## Design Principles

### Security First

The router architecture is built around the **Parse, don't validate** principle from the [AppSec Manifesto](https://github.com/dzentota/AppSecManifesto). Instead of simply validating input and passing strings around, the router:

1. **Parses** route parameters into strongly-typed domain objects
2. **Validates** parameters against domain constraints using TypedValue objects
3. **Ensures** type safety throughout the application

### Minimized Attack Surface

Following the **Absolute Zero** principle, the router:

- Eliminates dynamic route compilation in production when using cached routes
- Minimizes user-controlled input by requiring explicit type constraints
- Uses the least expressive language necessary for route definition

### Fail-Safe Design

The router implements **graceful degradation** and **fail-hard** strategies:

- Invalid input throws exceptions immediately (fail hard for interactive input)
- Type constraints are mandatory for all dynamic route segments
- Missing route names or invalid parameters result in clear exceptions

## Core Components

### Router Class

The main `Router` class serves as the central coordinator and implements several architectural patterns:

#### Route Registry Pattern
- Maintains internal registries for routes (`$rawRoutes`) and named routes (`$namedRoutes`)
- Provides methods to query and manipulate the route collection
- Supports route groups with prefix inheritance

#### Factory Pattern
- HTTP method convenience methods (`get()`, `post()`, etc.) act as factories
- `__call()` magic method provides a uniform interface for route creation

#### Builder Pattern
- Fluent interface allows method chaining for route definition
- Progressive configuration of routes with optional parameters

### Route Tree Structure

The router uses a tree-based data structure for efficient route matching:

```php
[
    '/' => [
        'name' => '/',
        'users' => [
            'name' => 'users',
            '*' => [
                'name' => 'id',
                'constraints' => ['id' => Id::class],
                'exec' => [
                    'route' => '/users/{id}',
                    'method' => ['GET' => 'UserController@show'],
                    'constraints' => ['id' => Id::class]
                ]
            ]
        ]
    ]
]
```

This structure provides:
- O(n) route matching where n is the number of path segments
- Efficient parameter extraction and validation
- Support for optional parameters with `?` suffix

### Type System Integration

The router integrates deeply with the TypedValue system:

```php
interface Typed {
    public static function validate($value): ValidationResult;
    public static function tryParse($value, &$typed): bool;
}
```

This ensures:
- **Type Safety**: All route parameters are parsed into domain objects
- **Validation**: Input is validated according to domain rules
- **Security**: Invalid input is rejected before entering the application

## Security Architecture

### Input Validation Strategy

The router implements multiple layers of input validation:

1. **Route Structure Validation**: Ensures routes match expected patterns
2. **Parameter Type Validation**: All parameters must have type constraints
3. **Domain Validation**: TypedValue objects validate according to business rules

### Attack Surface Minimization

Following AppSec principles:

#### No Dynamic Code Execution
- Routes are statically defined
- No eval() or dynamic code generation
- Route compilation is deterministic

#### Explicit Over Implicit
- All route parameters must have explicit type constraints
- No automatic type inference or coercion
- Named routes are explicitly registered

#### Fail Closed
- Unknown routes return 404 (NotFoundException)
- Invalid HTTP methods return 405 (MethodNotAllowedException) 
- Invalid parameters throw domain-specific exceptions

### URL Generation Security

The `generateUrl()` method provides secure URL generation:

```php
public function generateUrl(string $name, array $parameters = []): string
{
    // 1. Verify named route exists
    if (!isset($this->namedRoutes[$name])) {
        throw new \Exception("Named route '{$name}' not found");
    }

    // 2. Validate parameters against constraints
    foreach ($parameters as $key => $value) {
        if (isset($constraints[$key])) {
            if (!$constraintClass::tryParse($value, $typed)) {
                throw new \Exception("Parameter '{$key}' does not match constraint");
            }
        }
    }

    // 3. Generate URL safely
    return $this->buildUrl($route, $parameters);
}
```

This prevents:
- **URL Injection**: Parameters are validated before inclusion
- **Path Traversal**: Type constraints prevent malicious path components
- **Parameter Pollution**: Only expected parameters are processed

## Performance Considerations

### Route Compilation

The router supports two modes of operation:

#### Development Mode
- Routes are compiled on each request
- Provides flexibility for rapid development
- Includes comprehensive error reporting

#### Production Mode  
- Routes are pre-compiled using `dump()` and `load()`
- Eliminates compilation overhead
- Optimized tree structure for fast lookups

### Memory Efficiency

- Route tree is lazily compiled only when needed
- Named routes are stored separately to avoid overhead during matching
- Group prefixes are resolved at definition time, not runtime

### Caching Strategy

```php
// Generate and cache route tree
$routeTree = $router->dump();
file_put_contents('routes.cache', serialize($routeTree));

// Load cached routes in production
$router->load(unserialize(file_get_contents('routes.cache')));
```

## Extension Points

### Custom Type Constraints

Implement the `Typed` interface for domain-specific validation:

```php
class Slug implements Typed
{
    use TypedValue;
    
    public static function validate($value): ValidationResult
    {
        $result = new ValidationResult();
        if (!preg_match('/^[a-z0-9-]+$/', $value)) {
            $result->addError('Invalid slug format');
        }
        return $result;
    }
}
```

### Route Middleware Integration

While the router focuses on routing concerns, it provides hooks for middleware:

```php
// Route definition includes action for middleware resolution
$router->get('/protected', 'ProtectedController@action', [], 'protected');

// Application resolves middleware based on action
$route = $router->findRoute($method, $uri);
$middleware = $this->resolveMiddleware($route['action']);
```

## Testing Strategy

The architecture supports comprehensive testing:

### Unit Testing
- Each component is independently testable
- Type constraints can be tested in isolation
- Route tree generation is deterministic

### Integration Testing
- Full request/response cycle testing
- Security constraint validation
- Performance regression testing

### Security Testing
- Input fuzzing against type constraints
- Parameter pollution testing
- URL generation validation

## Future Considerations

### Planned Enhancements

1. **Route Model Binding**: Automatic model resolution from route parameters
2. **Route Caching**: More sophisticated caching strategies
3. **OpenAPI Integration**: Automatic API documentation generation
4. **Performance Monitoring**: Built-in performance metrics

### Backward Compatibility

The architecture maintains strict backward compatibility:
- New features are additive only
- Existing APIs remain stable
- Security enhancements are non-breaking

## Conclusion

The dzentota Router architecture prioritizes security, performance, and maintainability. By integrating deeply with the TypedValue system and following AppSec principles, it provides a robust foundation for secure web applications while maintaining developer productivity and application performance. 