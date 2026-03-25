<?php

declare(strict_types=1);

namespace dzentota\Router;

use dzentota\Router\Exception\InvalidConstraintException;
use dzentota\Router\Exception\InvalidRouteException;
use dzentota\Router\Exception\MethodNotAllowedException;
use dzentota\Router\Exception\NotFoundException;
use dzentota\Router\Middleware\MiddlewareStack;
use dzentota\Router\Middleware\RouteDispatchMiddleware;
use dzentota\Router\Middleware\RouteMatchMiddleware;
use dzentota\TypedValue\Typed;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Security-first PSR-15 compatible router.
 *
 * All route parameter constraints MUST implement {@see Typed} — following the
 * "parse, don't validate" principle from the AppSecManifesto. Input is never
 * trusted as a plain string; it is parsed into a typed value object before use.
 *
 * @method Route get(string $route, mixed $action, array $constraints = [], ?string $name = null)
 * @method Route post(string $route, mixed $action, array $constraints = [], ?string $name = null)
 * @method Route put(string $route, mixed $action, array $constraints = [], ?string $name = null)
 * @method Route delete(string $route, mixed $action, array $constraints = [], ?string $name = null)
 * @method Route patch(string $route, mixed $action, array $constraints = [], ?string $name = null)
 * @method Route head(string $route, mixed $action, array $constraints = [], ?string $name = null)
 * @method Route options(string $route, mixed $action, array $constraints = [], ?string $name = null)
 */
class Router
{
    /** @var Route[] */
    private array $rawRoutes = [];
    private ?array $routesTree = null;
    private array $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];
    protected string $currentGroupPrefix = '';

    /** @var Route[] Named routes indexed by name. */
    private array $namedRoutes = [];
    /** Reverse index: route pattern:METHOD → name, for O(1) lookup. */
    private array $routeNameIndex = [];

    /** Whether to auto-generate a name for unnamed single-method routes. */
    private bool $autoNaming = false;

    /** @var MiddlewareInterface[] Global middleware applied to every request via dispatch(). */
    private array $globalMiddleware = [];

    /** @var MiddlewareInterface[] Accumulated middleware for the current addGroup() scope. */
    private array $currentGroupMiddleware = [];

    /**
     * @param ContainerInterface|null $container  PSR-11 container for handler DI (used by dispatch()).
     * @param LoggerInterface|null    $logger     PSR-3 logger for dispatch error reporting.
     */
    public function __construct(
        private readonly ?ContainerInterface $container = null,
        private readonly ?LoggerInterface    $logger    = null,
    ) {}

    // -------------------------------------------------------------------------
    // Global & group middleware registration
    // -------------------------------------------------------------------------

    /**
     * Register one or more global middleware.
     *
     * Global middleware runs for **every** request, before route matching.
     * Multiple calls accumulate; middleware runs in registration order.
     *
     * Typical usage:
     * ```php
     * $router->middleware(new CorsMiddleware([...]));
     * $router->middleware(new CspMiddleware([...]));
     * $router->middleware(new CsrfMiddleware(...));
     * ```
     */
    public function middleware(MiddlewareInterface ...$middlewares): self
    {
        foreach ($middlewares as $mw) {
            $this->globalMiddleware[] = $mw;
        }
        return $this;
    }

    // -------------------------------------------------------------------------
    // Route registration
    // -------------------------------------------------------------------------

    /**
     * Add a route definition.
     *
     * Returns a fluent {@see Route} object so constraints, defaults, a name, and
     * tags can be added after registration:
     *
     * ```php
     * $router->get('/users/{id}', 'UserController@show')
     *        ->where(['id' => UserId::class])
     *        ->name('users.show')
     *        ->tag('api');
     * ```
     *
     * The optional positional arguments ($constraints, $name) are kept for
     * backward compatibility.
     *
     * @param  string|array        $method      HTTP method(s) or 'ANY'.
     * @param  string              $route       URI pattern.
     * @param  mixed               $action      Handler (callable, 'Class@method', 'Class::method', [class, method]).
     * @param  array               $constraints Typed constraints keyed by parameter name (legacy positional arg).
     * @param  string|null         $name        Route name (legacy positional arg).
     * @throws InvalidRouteException
     * @throws InvalidConstraintException
     */
    public function addRoute($method, string $route, mixed $action, array $constraints = [], ?string $name = null): Route
    {
        $method = (array)$method;
        $route  = $this->currentGroupPrefix . $route;

        // Guard against path traversal in route definitions.
        if (str_contains($route, '..')) {
            throw new InvalidRouteException("Route contains path traversal sequence: '{$route}'");
        }

        $validMethods = array_merge($this->allowedMethods, ['ANY']);
        if (array_diff($method, $validMethods)) {
            throw new InvalidRouteException('Method(s): ' . implode(', ', $method) . ' is not valid');
        }

        $methodsMap = [];
        foreach ($method === ['ANY'] ? $this->allowedMethods : $method as $m) {
            $methodsMap[$m] = $action;
        }

        // Eagerly validate the pattern (detects empty/duplicate param names immediately).
        $this->parseUri($route);

        $routeObj = new Route(
            $route,
            $methodsMap,
            $action,
            function (Route $r, string $n, ?string $o): void {
                $this->handleRouteName($r, $n, $o);
            },
        );

        if (!empty($this->currentGroupMiddleware)) {
            $routeObj->middleware(...$this->currentGroupMiddleware);
        }

        if (!empty($constraints)) {
            $routeObj->where($constraints);
        }

        $this->rawRoutes[] = $routeObj;

        // Invalidate cached tree so the new route is included on next access.
        $this->routesTree = null;

        if ($name !== null) {
            $routeObj->name($name);
        } elseif ($this->autoNaming && count($methodsMap) === 1) {
            $autoName = $this->generateAutoName($route, array_key_first($methodsMap));
            if (isset($this->namedRoutes[$autoName])) {
                throw new InvalidRouteException(
                    "Auto-name '{$autoName}' is already taken by '{$this->namedRoutes[$autoName]->getPattern()}'. "
                    . "Assign an explicit name to resolve the conflict."
                );
            }
            $routeObj->name($autoName);
        }

        return $routeObj;
    }

    /**
     * Create a route group with a common URI prefix and optional shared middleware.
     *
     * All routes registered inside the callback receive:
     * - The prefix prepended to their URI pattern.
     * - The group middleware prepended to their per-route middleware stack.
     *
     * Groups can be nested; middleware accumulates from outermost to innermost scope.
     *
     * ```php
     * $router->addGroup('/admin', function (Router $r) {
     *     $r->get('/dashboard', AdminDashController::class);
     * }, [new AuthMiddleware(), new AdminRoleMiddleware()]);
     * ```
     *
     * @param MiddlewareInterface[] $middleware Middleware applied to every route in this group.
     */
    public function addGroup(string $prefix, callable $callback, array $middleware = []): void
    {
        $previousGroupPrefix     = $this->currentGroupPrefix;
        $previousGroupMiddleware = $this->currentGroupMiddleware;

        $this->currentGroupPrefix     = $previousGroupPrefix . $prefix;
        $this->currentGroupMiddleware = array_merge($previousGroupMiddleware, $middleware);

        $callback($this);

        $this->currentGroupPrefix     = $previousGroupPrefix;
        $this->currentGroupMiddleware = $previousGroupMiddleware;
    }

    /**
     * Register a full set of RESTful resource routes.
     *
     * Generates the conventional seven routes:
     *   GET    /prefix              → Controller::index
     *   GET    /prefix/create       → Controller::create
     *   POST   /prefix              → Controller::store
     *   GET    /prefix/{id}         → Controller::show
     *   GET    /prefix/{id}/edit    → Controller::edit
     *   PUT|PATCH /prefix/{id}      → Controller::update
     *   DELETE /prefix/{id}         → Controller::destroy
     *
     * @param  string $prefix       URI prefix (e.g. '/users').
     * @param  string $controller   Fully-qualified controller class name.
     * @param  array  $idConstraint Typed constraint for {id}, e.g. ['id' => UserId::class].
     *                              Required for routes that contain {id}.
     */
    public function resource(string $prefix, string $controller, array $idConstraint = []): self
    {
        if (empty($idConstraint)) {
            throw new InvalidConstraintException(
                "resource('{$prefix}') requires a Typed constraint for the {id} parameter, "
                . "e.g. ['id' => YourIdClass::class]."
            );
        }
        $np = $this->resourceNamePrefix($this->currentGroupPrefix . $prefix);

        $this->addRoute('GET',              $prefix,              [$controller, 'index'],   [],           $np . '.index');
        $this->addRoute('GET',              $prefix . '/create',  [$controller, 'create'],  [],           $np . '.create');
        $this->addRoute('POST',             $prefix,              [$controller, 'store'],   [],           $np . '.store');
        $this->addRoute('GET',              $prefix . '/{id}',    [$controller, 'show'],    $idConstraint, $np . '.show');
        $this->addRoute('GET',              $prefix . '/{id}/edit', [$controller, 'edit'],  $idConstraint, $np . '.edit');
        $this->addRoute(['PUT', 'PATCH'],   $prefix . '/{id}',    [$controller, 'update'],  $idConstraint, $np . '.update');
        $this->addRoute('DELETE',           $prefix . '/{id}',    [$controller, 'destroy'], $idConstraint, $np . '.destroy');

        return $this;
    }

    /**
     * Register API resource routes (no HTML-form routes).
     *
     * Like {@see resource()} but omits the `create` (GET /prefix/create) and
     * `edit` (GET /prefix/{id}/edit) routes, which are only needed for
     * browser-based HTML forms.
     *
     * @param  string $prefix       URI prefix (e.g. '/api/posts').
     * @param  string $controller   Fully-qualified controller class name.
     * @param  array  $idConstraint Typed constraint for {id}, e.g. ['id' => PostId::class].
     */
    public function apiResource(string $prefix, string $controller, array $idConstraint = []): self
    {
        if (empty($idConstraint)) {
            throw new InvalidConstraintException(
                "apiResource('{$prefix}') requires a Typed constraint for the {id} parameter, "
                . "e.g. ['id' => YourIdClass::class]."
            );
        }
        $np = $this->resourceNamePrefix($this->currentGroupPrefix . $prefix);

        $this->addRoute('GET',             $prefix,           [$controller, 'index'],   [],            $np . '.index');
        $this->addRoute('POST',            $prefix,           [$controller, 'store'],   [],            $np . '.store');
        $this->addRoute('GET',             $prefix . '/{id}', [$controller, 'show'],    $idConstraint, $np . '.show');
        $this->addRoute(['PUT', 'PATCH'],  $prefix . '/{id}', [$controller, 'update'],  $idConstraint, $np . '.update');
        $this->addRoute('DELETE',          $prefix . '/{id}', [$controller, 'destroy'], $idConstraint, $np . '.destroy');

        return $this;
    }

    // -------------------------------------------------------------------------
    // Auto-naming
    // -------------------------------------------------------------------------

    /**
     * Enable automatic name generation for single-method routes that have no explicit name.
     *
     * Auto-generated names follow the pattern `{path-segments}.{method}`, e.g.:
     *   GET /admin/users/{id}  →  admin.users.id.get
     *
     * Explicit names set via addRoute() or ->name() always take precedence.
     */
    public function enableAutoNaming(): self
    {
        $this->autoNaming = true;
        return $this;
    }

    /** Disable automatic name generation (default). */
    public function disableAutoNaming(): self
    {
        $this->autoNaming = false;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Route discovery
    // -------------------------------------------------------------------------

    /**
     * Find route in route tree structure.
     *
     * @throws NotFoundException
     * @throws MethodNotAllowedException
     */
    public function findRoute(string $method, string $uri): array
    {
        if ($this->routesTree === null) {
            $this->routesTree = $this->parseRoutes($this->rawRoutes);
        }
        $search = $this->parseUri($uri);
        $node   = $this->routesTree;
        $params = [];

        foreach ($search as $v) {
            if (isset($node[$v['use']])) {
                $node = $node[$v['use']];
            } elseif (isset($node['*'])
                && ($val = $this->parseValue(
                    $node['*']['name'], $v['name'],
                    $node['*']['constraints'][$node['*']['name']] ?? null
                ))
            ) {
                $node              = $node['*'];
                $params[$node['name']] = $val;
            } elseif (isset($node['?'])
                && ($val = $this->parseValue(
                    $node['?']['name'], $v['name'],
                    $node['?']['constraints'][$node['?']['name']] ?? null
                ))
            ) {
                $node              = $node['?'];
                $params[$node['name']] = $val;
            } else {
                throw new NotFoundException('Route for uri: ' . $uri . ' was not found');
            }
        }

        // Traverse optional-parameter nodes when no exec is present yet.
        while (!isset($node['exec']) && isset($node['?'])) {
            $node = $node['?'];
        }

        if (!isset($node['exec'])) {
            throw new NotFoundException('Route for uri: ' . $uri . ' was not found');
        }

        // Apply defaults for optional parameters absent from the URI.
        // Defaults are parsed through their Typed constraints — handlers always receive
        // a Typed object, never a raw PHP value, regardless of whether the value came
        // from the URL or from a developer-defined default.
        if (!empty($node['exec']['defaults'])) {
            $methodDefaults = $node['exec']['defaults'][$method]
                ?? ($method === 'HEAD' ? ($node['exec']['defaults']['GET'] ?? []) : []);
            foreach ($methodDefaults as $param => $spec) {
                if (!array_key_exists($param, $params)) {
                    $params[$param] = $spec['value'] === null
                        ? null
                        : $this->parseValue($param, (string)$spec['value'], $spec['constraint']);
                }
            }
        }

        $exec = $node['exec'];

        if (isset($exec['method'][$method]) || isset($exec['method']['ANY'])) {
            return [
                'route'      => $exec['route'],
                'method'     => $method,
                'action'     => $exec['method'][$method] ?? $exec['method']['ANY'],
                'params'     => $params,
                'middleware' => $exec['middleware'][$method] ?? [],
            ];
        }

        if ($method === 'HEAD' && isset($exec['method']['GET'])) {
            return [
                'route'      => $exec['route'],
                'method'     => $method,
                'action'     => $exec['method']['GET'],
                'params'     => $params,
                'middleware' => $exec['middleware']['HEAD'] ?? $exec['middleware']['GET'] ?? [],
            ];
        }

        throw new MethodNotAllowedException(
            'Method: ' . $method . ' is not allowed for this route',
            array_keys($exec['method'])
        );
    }

    // -------------------------------------------------------------------------
    // Tree serialisation
    // -------------------------------------------------------------------------

    /**
     * Build and return the compiled routes tree.
     *
     * **For in-process (in-memory) use only.**
     * The returned array contains live PHP objects (constraint instances,
     * middleware, closures) and MUST NOT be serialised with PHP's
     * {@see serialize()} — doing so opens a PHP Object Injection vulnerability
     * and will fail at runtime for closure handlers anyway.
     *
     * Use {@see exportCache()} / {@see importCache()} for file-based caching.
     */
    public function dump(): ?array
    {
        if ($this->routesTree === null) {
            $this->routesTree = $this->parseRoutes($this->rawRoutes);
        }
        return $this->routesTree;
    }

    /**
     * Replace the routes tree with a previously {@see dump()}'d array.
     *
     * **For in-process (in-memory) use only** (e.g. testing, sharing a compiled
     * tree between router instances in the same request lifecycle).
     * Never pass data obtained from an untrusted source or from PHP's
     * {@see unserialize()} — that enables PHP Object Injection.
     *
     * Use {@see importCache()} to restore routes from a file-based cache.
     */
    public function load(array $routes): self
    {
        $this->routesTree = $routes;
        return $this;
    }

    // -------------------------------------------------------------------------
    // File-based route cache (safe serialisation via JSON)
    // -------------------------------------------------------------------------

    /**
     * Export all registered routes as a JSON string suitable for file-based caching.
     *
     * Unlike {@see dump()} + PHP `serialize()`, this method is **safe**:
     * - Uses JSON (not PHP serialization) — PHP Object Injection is impossible.
     * - Stores only JSON-native values: pattern strings, HTTP method arrays,
     *   string/array handlers, constraint class-name strings, scalar defaults,
     *   and route names/tags.
     * - Per-route and group middleware objects are **excluded** — they are PHP
     *   code and must be re-attached after loading the cache (via
     *   {@see Route::middleware()} or {@see addGroup()}).
     *
     * Typical usage:
     * ```php
     * file_put_contents('routes.cache.json', $router->exportCache());
     * ```
     *
     * @throws \LogicException If any route handler is a Closure (closures cannot
     *                         be represented in JSON; use string handlers instead,
     *                         e.g. 'UserController@show').
     * @return string JSON-encoded cache payload.
     */
    public function exportCache(): string
    {
        $routes = [];
        foreach ($this->rawRoutes as $route) {
            $handler = $route->getAction();
            if ($handler instanceof \Closure) {
                throw new \LogicException(
                    "Route '{$route->getPattern()}' uses a Closure handler which cannot be "
                    . "exported to a JSON cache. Use a string handler (e.g. 'Controller@method') "
                    . "or a [ClassName, method] array instead."
                );
            }
            $routes[] = [
                'methods'     => array_keys($route->getMethodMap()),
                'pattern'     => $route->getPattern(),
                'handler'     => $handler,
                'constraints' => $route->getConstraints(),
                'defaults'    => $route->getDefaults(),
                'name'        => $route->getName(),
                'tags'        => $route->getTags(),
            ];
        }

        return json_encode(['version' => 1, 'routes' => $routes], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * Import routes from a JSON cache string previously produced by {@see exportCache()}.
     *
     * This method uses `json_decode()` — never `unserialize()` — making it immune
     * to PHP Object Injection. The payload is strictly validated; any structural
     * deviation throws an `\InvalidArgumentException`.
     *
     * Per-route middleware (excluded during export) must be re-attached after import
     * if required:
     * ```php
     * $router->importCache(file_get_contents('routes.cache.json'));
     * // Re-attach middleware that cannot live in the cache file:
     * $router->get('/admin/dashboard', 'AdminController@index')
     *        ->middleware($authMiddleware);  // overrides any prior registration
     * ```
     *
     * @param  string $json JSON string produced by {@see exportCache()}.
     * @throws \InvalidArgumentException On invalid/tampered JSON or unexpected structure.
     * @throws InvalidConstraintException If a cached constraint class does not implement Typed.
     * @throws InvalidRouteException      If a cached route pattern or name is invalid.
     */
    public function importCache(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Route cache contains invalid JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data) || ($data['version'] ?? null) !== 1 || !isset($data['routes']) || !is_array($data['routes'])) {
            throw new \InvalidArgumentException('Route cache has an unrecognised format (expected version 1).');
        }

        // Clear existing routes so the cache is the sole source of truth.
        $this->rawRoutes   = [];
        $this->routesTree  = null;
        $this->namedRoutes = [];
        $this->routeNameIndex = [];

        foreach ($data['routes'] as $index => $entry) {
            $this->validateCacheEntry($entry, $index);

            $routeObj = $this->addRoute(
                $entry['methods'],
                $entry['pattern'],
                $entry['handler'],
            );

            if (!empty($entry['constraints'])) {
                $routeObj->where($entry['constraints']);
            }
            if (!empty($entry['defaults'])) {
                $routeObj->defaults($entry['defaults']);
            }
            if (!empty($entry['tags'])) {
                $routeObj->tag($entry['tags']);
            }
            if ($entry['name'] !== null) {
                $routeObj->name($entry['name']);
            }
        }

        return $this;
    }

    /**
     * Validate a single decoded cache entry; throws on malformed data.
     *
     * @throws \InvalidArgumentException
     */
    private function validateCacheEntry(mixed $entry, int $index): void
    {
        if (!is_array($entry)) {
            throw new \InvalidArgumentException("Route cache entry #{$index} is not an array.");
        }

        $required = ['methods', 'pattern', 'handler', 'constraints', 'defaults', 'name', 'tags'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $entry)) {
                throw new \InvalidArgumentException("Route cache entry #{$index} is missing key '{$key}'.");
            }
        }

        if (!is_array($entry['methods']) || empty($entry['methods'])) {
            throw new \InvalidArgumentException("Route cache entry #{$index}: 'methods' must be a non-empty array.");
        }
        foreach ($entry['methods'] as $m) {
            if (!is_string($m)) {
                throw new \InvalidArgumentException("Route cache entry #{$index}: each method must be a string.");
            }
        }

        if (!is_string($entry['pattern']) || $entry['pattern'] === '') {
            throw new \InvalidArgumentException("Route cache entry #{$index}: 'pattern' must be a non-empty string.");
        }

        // Handler must be a string or a 2-element [class, method] array — no objects.
        $handler = $entry['handler'];
        if (
            !is_string($handler) &&
            !(is_array($handler) && count($handler) === 2 && is_string($handler[0]) && is_string($handler[1]))
        ) {
            throw new \InvalidArgumentException(
                "Route cache entry #{$index}: 'handler' must be a string or a [class, method] array of strings."
            );
        }

        if (!is_array($entry['constraints'])) {
            throw new \InvalidArgumentException("Route cache entry #{$index}: 'constraints' must be an array.");
        }
        foreach ($entry['constraints'] as $param => $class) {
            if (!is_string($param) || !is_string($class)) {
                throw new \InvalidArgumentException(
                    "Route cache entry #{$index}: each constraint must be a string param => string class mapping."
                );
            }
        }

        if (!is_array($entry['defaults'])) {
            throw new \InvalidArgumentException("Route cache entry #{$index}: 'defaults' must be an array.");
        }
        foreach ($entry['defaults'] as $param => $value) {
            if (!is_string($param)) {
                throw new \InvalidArgumentException("Route cache entry #{$index}: default keys must be strings.");
            }
            if (!is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException(
                    "Route cache entry #{$index}: default values must be scalar or null."
                );
            }
        }

        if ($entry['name'] !== null && !is_string($entry['name'])) {
            throw new \InvalidArgumentException("Route cache entry #{$index}: 'name' must be a string or null.");
        }

        if (!is_array($entry['tags'])) {
            throw new \InvalidArgumentException("Route cache entry #{$index}: 'tags' must be an array.");
        }
        foreach ($entry['tags'] as $tag) {
            if (!is_string($tag)) {
                throw new \InvalidArgumentException("Route cache entry #{$index}: each tag must be a string.");
            }
        }
    }

    // -------------------------------------------------------------------------
    // URL generation
    // -------------------------------------------------------------------------

    /**
     * Generate a URL from a named route and optional parameters.
     *
     * Parameters are validated against their Typed constraints before being
     * interpolated into the URL pattern.
     *
     * @param  string $name       Named route identifier.
     * @param  array  $parameters Values keyed by parameter name.
     * @throws InvalidRouteException When the route is not found, a required parameter
     *                               is missing, or a value fails its constraint.
     */
    public function generateUrl(string $name, array $parameters = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new InvalidRouteException("Named route '{$name}' not found");
        }

        $routeObj   = $this->namedRoutes[$name];
        $pattern    = $routeObj->getPattern();
        $constraints = $routeObj->getConstraints();

        $url = $pattern;
        foreach ($parameters as $key => $value) {
            if (isset($constraints[$key])) {
                $constraintClass = $constraints[$key];
                if (!$constraintClass::tryParse($value, $typed)) {
                    throw new InvalidRouteException(
                        "Parameter '{$key}' does not match constraint for route '{$name}'"
                    );
                }
                $value = $typed->toNative();
            }
            $url = str_replace(['{' . $key . '}', '{' . $key . '?}'], (string)$value, $url);
        }

        // Detect remaining required parameters (possessive quantifier prevents ReDoS).
        if (preg_match('/\{([^?}]++)\}/', $url, $matches)) {
            throw new InvalidRouteException(
                "Missing required parameter '{$matches[1]}' for route '{$name}'"
            );
        }

        // Remove optional parameters that were not supplied.
        $url = preg_replace('/\{[^?}]++\?\}/', '', $url);

        // Collapse double-slashes left by optional segment removal.
        $url = preg_replace('#/++#', '/', $url);
        $url = rtrim($url, '/');

        return $url !== '' ? $url : '/';
    }

    // -------------------------------------------------------------------------
    // Named route accessors
    // -------------------------------------------------------------------------

    /**
     * Look up the name registered for a given route pattern + HTTP method in O(1).
     * Returns null when no name is registered for that combination.
     *
     * Passing the HTTP method is required so that routes sharing the same URI
     * pattern but registered under different names (e.g. resource routes) resolve
     * correctly (e.g. GET /posts/{id} → 'posts.show', DELETE /posts/{id} → 'posts.destroy').
     */
    public function findNameForRoute(string $routePattern, string $method): ?string
    {
        return $this->routeNameIndex[$routePattern . ':' . strtoupper($method)] ?? null;
    }

    /**
     * Return all named routes as an associative array for backward compatibility.
     *
     * Format: `[name => ['route' => pattern, 'action' => handler, 'constraints' => [...]]]`
     */
    public function getNamedRoutes(): array
    {
        $result = [];
        foreach ($this->namedRoutes as $name => $route) {
            $result[$name] = [
                'route'       => $route->getPattern(),
                'action'      => $route->getAction(),
                'constraints' => $route->getConstraints(),
            ];
        }
        return $result;
    }

    /** Return true when a named route with the given name exists. */
    public function hasRoute(string $name): bool
    {
        return isset($this->namedRoutes[$name]);
    }

    /**
     * Return all Route objects that carry a given tag.
     *
     * @return Route[]
     */
    public function getRoutesByTag(string $tag): array
    {
        return array_values(
            array_filter($this->rawRoutes, fn(Route $r) => in_array($tag, $r->getTags(), true))
        );
    }

    // -------------------------------------------------------------------------
    // Statistics
    // -------------------------------------------------------------------------

    /**
     * Return a summary of all registered routes.
     *
     * Keys returned:
     * - `total`   — total number of Route objects registered
     * - `named`   — number of routes that have a name
     * - `tagged`  — number of routes that carry at least one tag
     * - `methods` — map of HTTP method → number of routes that accept it
     * - `tags`    — map of tag → number of routes carrying it
     *
     * @return array{total:int, named:int, tagged:int, methods:array<string,int>, tags:array<string,int>}
     */
    public function getStats(): array
    {
        $methods    = [];
        $named      = 0;
        $tagged     = 0;
        $tagCounts  = [];

        foreach ($this->rawRoutes as $route) {
            foreach (array_keys($route->getMethodMap()) as $m) {
                $methods[$m] = ($methods[$m] ?? 0) + 1;
            }
            if ($route->getName() !== null) {
                $named++;
            }
            if (!empty($route->getTags())) {
                $tagged++;
                foreach ($route->getTags() as $tag) {
                    $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
                }
            }
        }
        ksort($methods);

        return [
            'total'   => count($this->rawRoutes),
            'named'   => $named,
            'tagged'  => $tagged,
            'methods' => $methods,
            'tags'    => $tagCounts,
        ];
    }

    /** Return the raw Route objects array. */
    public function getRawRoutes(): array
    {
        return $this->rawRoutes;
    }

    // -------------------------------------------------------------------------
    // Simple dispatch API
    // -------------------------------------------------------------------------

    /**
     * Build and execute the full middleware + routing pipeline in one call.
     *
     * This is the recommended entry point for most applications. It eliminates
     * the need to remember the correct ordering of `MiddlewareStack::create()`:
     *
     * ```php
     * $router = new Router();
     *
     * // 1. Register global middleware (run for every request, in order)
     * $router->middleware(new CorsMiddleware([...]));
     * $router->middleware(new CsrfMiddleware(...));
     *
     * // 2. Define routes (with optional per-route or per-group middleware)
     * $router->get('/public', 'PublicController@index');
     *
     * $router->addGroup('/admin', function (Router $r) {
     *     $r->get('/dashboard', 'AdminController@dashboard');
     * }, [new AuthMiddleware()]);
     *
     * $router->get('/upload', 'UploadController')
     *        ->middleware(new MaxFileSizeMiddleware(10));
     *
     * // 3. Dispatch — everything is wired automatically
     * $response = $router->dispatch($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
     * ```
     *
     * **Pipeline order (automatically):**
     * 1. Global middleware (registered via {@see middleware()})
     * 2. `RouteMatchMiddleware` — finds the route, sets request attributes
     * 3. Per-route / per-group middleware (from the matched route)
     * 4. `RouteDispatchMiddleware` — executes the handler
     *
     * @param string                    $uri     Request URI (e.g. `$_SERVER['REQUEST_URI']`).
     * @param string                    $method  HTTP method (e.g. `$_SERVER['REQUEST_METHOD']`).
     * @param ServerRequestInterface|null $request Pre-built PSR-7 request; when null a minimal
     *                                             request is created from $uri and $method.
     */
    public function dispatch(
        string $uri,
        string $method,
        ?ServerRequestInterface $request = null,
    ): ResponseInterface {
        $factory = new Psr17Factory();

        if ($request === null) {
            $request = $factory->createServerRequest($method, $uri);
        }

        // The final handler is a 204 no-content stub — it should never be reached
        // because RouteDispatchMiddleware already handles 404/405 internally.
        $finalHandler = new class($factory) implements RequestHandlerInterface {
            public function __construct(private readonly Psr17Factory $f) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->f->createResponse(204);
            }
        };

        $layers = [
            ...$this->globalMiddleware,
            new RouteMatchMiddleware($this),
            new RouteDispatchMiddleware($this->container, $this->logger),
        ];

        $stack = MiddlewareStack::create($finalHandler, ...$layers);

        return $stack->handle($request);
    }

    // -------------------------------------------------------------------------
    // Magic shortcut methods (get/post/put/patch/delete/head/options)
    // -------------------------------------------------------------------------

    public function __call(string $method, array $params): Route
    {
        $method = strtoupper($method);
        if (!in_array($method, $this->allowedMethods)) {
            throw new \BadMethodCallException('Call to undefined method');
        }

        return $this->addRoute(
            $method,
            $params[0] ?? '',
            $params[1] ?? '',
            $params[2] ?? [],
            $params[3] ?? null,
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers called via closure injected into Route
    // -------------------------------------------------------------------------

    /**
     * Register (or rename) a route name in the router's internal indexes.
     *
     * Called via the closure passed to the Route constructor.
     * Uses a compound `pattern:METHOD` key in routeNameIndex so that routes
     * sharing the same URI pattern but different HTTP methods resolve to the
     * correct name (e.g. resource routes).
     */
    private function handleRouteName(Route $route, string $name, ?string $oldName): void
    {
        if ($oldName !== null) {
            unset($this->namedRoutes[$oldName]);
            foreach (array_keys($route->getMethodMap()) as $m) {
                unset($this->routeNameIndex[$route->getPattern() . ':' . $m]);
            }
        }
        $this->namedRoutes[$name] = $route;
        foreach (array_keys($route->getMethodMap()) as $m) {
            $this->routeNameIndex[$route->getPattern() . ':' . $m] = $name;
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function parseValue(string $param, string $value, mixed $constraint): ?Typed
    {
        if (empty($constraint)) {
            throw new InvalidConstraintException("The parameter '{$param}' has no constraints");
        }
        /** @var Typed $constraint */
        $constraint::tryParse($value, $typed);
        return $typed;
    }

    /**
     * Parse a URI pattern or a request URI into a segment array.
     *
     * Each entry has:
     *  - `name` — the raw segment text or parameter name.
     *  - `use`  — `'*'` (mandatory param), `'?'` (optional param), or the literal text.
     *
     * @throws InvalidRouteException
     */
    protected function parseUri(string $route): array
    {
        $chunks    = explode('/', '/' . trim($route, '/'));
        $chunks[0] = '/';
        $parsed    = [];
        $seenParams = [];

        foreach ($chunks as $chunk) {
            if (str_starts_with($chunk, '{')) {
                if (str_ends_with($chunk, '?}')) {
                    $name = mb_substr($chunk, 1, -2);
                    if ($name === '') {
                        throw new InvalidRouteException(
                            "Route '{$route}' contains a parameter with an empty name"
                        );
                    }
                    if (isset($seenParams[$name])) {
                        throw new InvalidRouteException(
                            "Route '{$route}' contains duplicate parameter name '{$name}'"
                        );
                    }
                    $seenParams[$name] = true;
                    $parsed[] = ['name' => $name, 'use' => '?'];
                } elseif (str_ends_with($chunk, '}')) {
                    $name = mb_substr($chunk, 1, -1);
                    if ($name === '') {
                        throw new InvalidRouteException(
                            "Route '{$route}' contains a parameter with an empty name"
                        );
                    }
                    if (isset($seenParams[$name])) {
                        throw new InvalidRouteException(
                            "Route '{$route}' contains duplicate parameter name '{$name}'"
                        );
                    }
                    $seenParams[$name] = true;
                    $parsed[] = ['name' => $name, 'use' => '*'];
                } else {
                    $parsed[] = ['name' => $chunk, 'use' => $chunk];
                }
            } else {
                $parsed[] = ['name' => $chunk, 'use' => $chunk];
            }
        }
        return $parsed;
    }

    /**
     * Build the route tree from an array of Route objects.
     *
     * @param Route[] $routes
     */
    protected function parseRoutes(array $routes): array
    {
        $tree = [];
        foreach ($routes as $route) {
            $node     = &$tree;
            $segments = $this->parseUri($route->getPattern());

            foreach ($segments as $segment) {
                if (!isset($node[$segment['use']])) {
                    $node[$segment['use']] = ['name' => $segment['name']];
                }
                if ($segment['use'] === '*' || $segment['use'] === '?') {
                    $node[$segment['use']]['constraints'] = $route->getConstraints();
                }
                $node = &$node[$segment['use']];
            }

            if (isset($node['exec'])) {
                // Same pattern registered again with different methods — merge.
                $node['exec']['method'] = array_merge($node['exec']['method'], $route->getMethodMap());
                if (!empty($route->getDefaults())) {
                    $defaultSpecs = $this->buildDefaultSpecs($route);
                    foreach (array_keys($route->getMethodMap()) as $m) {
                        $node['exec']['defaults'][$m] = $defaultSpecs;
                    }
                }
                if (!empty($route->getMiddleware())) {
                    foreach (array_keys($route->getMethodMap()) as $m) {
                        $node['exec']['middleware'][$m] = $route->getMiddleware();
                    }
                }
            } else {
                $node['exec'] = [
                    'route'  => $route->getPattern(),
                    'method' => $route->getMethodMap(),
                ];
                if (!empty($route->getDefaults())) {
                    $defaultSpecs = $this->buildDefaultSpecs($route);
                    foreach (array_keys($route->getMethodMap()) as $m) {
                        $node['exec']['defaults'][$m] = $defaultSpecs;
                    }
                }
                if (!empty($route->getMiddleware())) {
                    foreach (array_keys($route->getMethodMap()) as $m) {
                        $node['exec']['middleware'][$m] = $route->getMiddleware();
                    }
                }
            }

            if (isset($segment['name'])) {
                $node['name'] = $segment['name'];
            }
        }
        return $tree;
    }

    /**
     * Build the default-spec array for a route's defaults, validating each value
     * against its Typed constraint at tree-build time.
     *
     * Each entry is: `['value' => mixed, 'constraint' => class-string<Typed>|null]`
     *
     * Rules:
     * - A non-null default without a corresponding constraint throws immediately.
     * - A non-null default that fails its constraint throws immediately, catching
     *   developer errors at startup rather than at the first matching request.
     * - A null default is stored as-is (represents "explicitly absent").
     *
     * @return array<string, array{value: mixed, constraint: class-string<Typed>|null}>
     *
     * @throws InvalidConstraintException when a default has no Typed constraint.
     * @throws InvalidRouteException      when a default value is rejected by its constraint.
     */
    private function buildDefaultSpecs(Route $route): array
    {
        $constraints = $route->getConstraints();
        $specs       = [];

        foreach ($route->getDefaults() as $param => $value) {
            $constraint = $constraints[$param] ?? null;

            if ($value !== null) {
                if ($constraint === null) {
                    throw new InvalidConstraintException(
                        "Default for '{$param}' on '{$route->getPattern()}' has no Typed constraint. "
                        . "Add ->where(['{$param}' => YourType::class]) to the route."
                    );
                }
                // Validate the default value at tree-build time (startup), not per-request.
                if (!$constraint::tryParse((string)$value, $validated) || $validated === null) {
                    throw new InvalidRouteException(
                        "Default value '{$value}' for '{$param}' on '{$route->getPattern()}' "
                        . "is rejected by constraint {$constraint}."
                    );
                }
            }

            $specs[$param] = ['value' => $value, 'constraint' => $constraint];
        }

        return $specs;
    }

    /**
     * Derive a dot-separated name prefix from a URI prefix.
     * E.g. '/api/v1/users' → 'api.v1.users'
     */
    private function resourceNamePrefix(string $prefix): string
    {
        $name = preg_replace('/\{[^?}]++\??\}/', '', trim($prefix, '/'));
        $name = preg_replace('/[^a-zA-Z0-9]+/', '.', $name);
        return trim($name, '.');
    }

    /**
     * Generate an automatic route name from a pattern and HTTP method.
     * E.g. '/admin/users/{id}' + 'GET' → 'admin.users.id.get'
     */
    private function generateAutoName(string $pattern, string $method): string
    {
        $name = trim($pattern, '/');
        $name = preg_replace('/\{([^?}]++)\??\}/', '$1', $name);
        $name = preg_replace('/[^a-zA-Z0-9]+/', '.', $name);
        $name = trim($name, '.');
        $name = $name !== '' ? $name : 'root';
        return $name . '.' . strtolower($method);
    }
}
