<?php

declare(strict_types=1);

namespace dzentota\Router;

use dzentota\Router\Exception\InvalidConstraintException;
use dzentota\Router\Exception\InvalidRouteException;
use dzentota\Router\Exception\MethodNotAllowedException;
use dzentota\Router\Exception\NotFoundException;
use dzentota\TypedValue\Typed;

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
    /** Reverse index: route pattern → name, for O(1) lookup in RouteMatchMiddleware. */
    private array $routeNameIndex = [];

    /** Whether to auto-generate a name for unnamed single-method routes. */
    private bool $autoNaming = false;

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
     * Create a route group with a common URI prefix.
     *
     * All routes registered inside the callback receive the prefix prepended.
     */
    public function addGroup(string $prefix, callable $callback): void
    {
        $previousGroupPrefix      = $this->currentGroupPrefix;
        $this->currentGroupPrefix = $previousGroupPrefix . $prefix;
        $callback($this);
        $this->currentGroupPrefix = $previousGroupPrefix;
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
                'route'  => $exec['route'],
                'method' => $method,
                'action' => $exec['method'][$method] ?? $exec['method']['ANY'],
                'params' => $params,
            ];
        }

        if ($method === 'HEAD' && isset($exec['method']['GET'])) {
            return [
                'route'  => $exec['route'],
                'method' => $method,
                'action' => $exec['method']['GET'],
                'params' => $params,
            ];
        }

        throw new MethodNotAllowedException(
            'Method: ' . $method . ' is not allowed for this route',
            $exec['method']
        );
    }

    // -------------------------------------------------------------------------
    // Tree serialisation
    // -------------------------------------------------------------------------

    /**
     * Build and return the compiled routes tree.
     *
     * The returned array can be serialised and later restored via {@see load()} to
     * skip the parse step on every request.
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
     * This overwrites any routes registered via addRoute().
     */
    public function load(array $routes): self
    {
        $this->routesTree = $routes;
        return $this;
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
