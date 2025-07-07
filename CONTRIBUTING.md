# Contributing to dzentota Router

Thank you for your interest in contributing to the dzentota Router! This document provides guidelines and information for contributors.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [Getting Started](#getting-started)
- [Development Setup](#development-setup)
- [Coding Standards](#coding-standards)
- [Security Guidelines](#security-guidelines)
- [Testing](#testing)
- [Pull Request Process](#pull-request-process)
- [Issue Guidelines](#issue-guidelines)
- [Documentation](#documentation)

## Code of Conduct

This project and everyone participating in it is governed by our commitment to creating a welcoming and inclusive environment. We expect all contributors to:

- Use welcoming and inclusive language
- Be respectful of differing viewpoints and experiences
- Gracefully accept constructive criticism
- Focus on what is best for the community
- Show empathy towards other community members

## Getting Started

### Prerequisites

- PHP 8.0 or higher
- Composer
- Git

### Fork and Clone

1. Fork the repository on GitHub
2. Clone your fork locally:
   ```bash
   git clone https://github.com/YOUR_USERNAME/router.git
   cd router
   ```

3. Add the upstream remote:
   ```bash
   git remote add upstream https://github.com/dzentota/router.git
   ```

## Development Setup

1. Install dependencies:
   ```bash
   composer install
   ```

2. Run tests to ensure everything is working:
   ```bash
   composer test
   ```

3. Create a feature branch:
   ```bash
   git checkout -b feature/your-feature-name
   ```

## Coding Standards

### PHP Standards

We follow [PSR-12](https://www.php-fig.org/psr/psr-12/) coding standards with some additional rules:

#### Type Declarations
- Always use strict types: `declare(strict_types=1);`
- Use type hints for all parameters and return values
- Use nullable types (`?Type`) when appropriate

```php
<?php

declare(strict_types=1);

namespace dzentota\Router;

class Example
{
    public function method(string $param, ?int $nullable = null): bool
    {
        // Implementation
        return true;
    }
}
```

#### Documentation
- All public methods must have PHPDoc comments
- Include parameter types, return types, and `@throws` annotations
- Use meaningful variable names that don't require comments

```php
/**
 * Generate URL from named route with parameter validation
 *
 * @param string $name Route name
 * @param array $parameters Route parameters
 * @return string Generated URL
 * @throws \Exception When route not found or parameters invalid
 */
public function generateUrl(string $name, array $parameters = []): string
{
    // Implementation
}
```

### Security-First Coding

Following the [AppSec Manifesto](https://github.com/dzentota/AppSecManifesto) principles:

#### Input Validation
- **Parse, don't validate**: Transform input into typed objects
- Never trust user input - always validate against domain constraints
- Fail hard on invalid input for interactive operations

```php
// Good: Parse into domain object
public function addRoute(string $method, string $route, string $action, array $constraints = []): Router
{
    if (array_diff((array)$method, $this->allowedMethods)) {
        throw new \Exception('Invalid method'); // Fail hard
    }
    
    // Transform and validate...
}

// Bad: Just validate and continue with strings
public function addRoute(string $method, string $route): Router
{
    if (is_valid($method)) {
        // Continue with untyped data...
    }
}
```

#### Minimize Attack Surface
- Keep classes and methods as small as possible
- Avoid dynamic code execution
- Use explicit over implicit behavior

#### Type Safety
- Leverage PHP's type system fully
- Use TypedValue objects for all user input
- Prefer composition over inheritance

## Security Guidelines

### Threat Model

When contributing, consider these security aspects:

1. **Input Validation**: All user-controlled input must be validated
2. **Type Safety**: Maintain strict typing throughout
3. **URL Generation**: Prevent injection attacks in generated URLs  
4. **Route Pollution**: Prevent malicious route definitions
5. **Performance**: Avoid DoS vectors in route matching

### Security Review Checklist

Before submitting code, ensure:

- [ ] All user input is validated using TypedValue constraints
- [ ] No dynamic code execution (eval, create_function, etc.)
- [ ] Exception messages don't leak sensitive information
- [ ] New features include security tests
- [ ] Performance implications are considered

### Reporting Security Issues

**DO NOT** open public issues for security vulnerabilities. Instead:

1. Email security issues to: [security email to be added]
2. Include detailed description and reproduction steps
3. Allow reasonable time for response before disclosure

## Testing

### Test Requirements

All contributions must include appropriate tests:

#### Unit Tests
- Test all public methods
- Test error conditions and edge cases
- Maintain 100% code coverage for new code

#### Security Tests
- Test input validation boundaries
- Test malicious input scenarios
- Test constraint bypass attempts

#### Integration Tests
- Test complete request/response cycles
- Test with various TypedValue implementations
- Test route caching scenarios

### Running Tests

```bash
# Run all tests
composer test

# Run with coverage
composer test:coverage

# Run specific test file
./vendor/bin/phpunit tests/RouterTest.php

# Run security-focused tests
./vendor/bin/phpunit --group security
```

### Test Structure

Follow this structure for test methods:

```php
public function testMethodNameScenario(): void
{
    // Arrange
    $router = new Router();
    $expectedResult = 'expected';
    
    // Act
    $actualResult = $router->someMethod();
    
    // Assert
    self::assertEquals($expectedResult, $actualResult);
}
```

### Security Test Examples

```php
public function testGenerateUrlRejectsInvalidParameters(): void
{
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage("Parameter 'id' does not match constraint");
    
    $router = new Router();
    $router->get('/users/{id}', 'UserShow', ['id' => Id::class], 'users.show');
    
    // This should fail due to constraint validation
    $router->generateUrl('users.show', ['id' => 'invalid']);
}
```

## Pull Request Process

### Before Submitting

1. **Sync with upstream**:
   ```bash
   git fetch upstream
   git rebase upstream/master
   ```

2. **Run full test suite**:
   ```bash
   composer test
   ```

3. **Check coding standards**:
   ```bash
   composer cs:check
   ```

4. **Update documentation** if needed

### PR Guidelines

1. **Title**: Use descriptive titles that explain the change
2. **Description**: Include:
   - What changed and why
   - Any breaking changes
   - Security implications
   - Testing performed

3. **Size**: Keep PRs focused and reasonably sized
4. **Commits**: Use clear, descriptive commit messages

### Example PR Template

```markdown
## Description
Brief description of changes

## Type of Change
- [ ] Bug fix (non-breaking change)
- [ ] New feature (non-breaking change)
- [ ] Breaking change
- [ ] Documentation update

## Security Impact
- [ ] No security implications
- [ ] Improves security
- [ ] Potential security impact (explain below)

## Testing
- [ ] Unit tests pass
- [ ] Integration tests pass
- [ ] Security tests added/updated
- [ ] Manual testing performed

## Documentation
- [ ] README updated
- [ ] ARCHITECTURE.md updated
- [ ] PHPDoc comments added/updated
```

## Issue Guidelines

### Bug Reports

Include:
- PHP version
- Detailed steps to reproduce
- Expected vs actual behavior
- Minimal code example
- Error messages/stack traces

### Feature Requests

Include:
- Clear description of the feature
- Use case and motivation
- Proposed API (if applicable)
- Security considerations
- Backward compatibility impact

### Security Issues

Use the security reporting process described above.

## Documentation

### Requirements
- All public APIs must be documented
- Include code examples
- Document security considerations
- Keep README.md updated

### Style Guide
- Use clear, concise language
- Include practical examples
- Explain security implications
- Link to relevant resources

### Documentation Types

1. **API Documentation**: PHPDoc comments in code
2. **Usage Examples**: In README.md and dedicated example files
3. **Architecture**: In ARCHITECTURE.md
4. **Security**: Security implications and best practices

## Release Process

*Note: This section is for maintainers*

1. Update version numbers
2. Update CHANGELOG.md
3. Run full security review
4. Create release branch
5. Tag release
6. Update documentation

## Questions?

- Open an issue for questions about development
- Check existing issues and documentation first
- Be patient and respectful when asking for help

## License

By contributing, you agree that your contributions will be licensed under the same license as the project (MIT License).

---

Thank you for contributing to dzentota Router! Your efforts help make web applications more secure and maintainable. 