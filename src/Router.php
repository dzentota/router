<?php

declare(strict_types=1);

namespace dzentota\Router;

use dzentota\Router\Exception\InvalidConstraintException;
use dzentota\Router\Exception\InvalidRouteException;
use dzentota\Router\Exception\MethodNotAllowedException;
use dzentota\Router\Exception\NotFoundException;
use dzentota\TypedValue\Typed;

/**
 * @method get(string $route, string|callable $action, array $constraints = [], ?string $name = null)
 * @method post(string $route, string|callable $action, array $constraints = [], ?string $name = null)
 * @method put(string $route, string|callable $action, array $constraints = [], ?string $name = null)
 * @method delete(string $route, string|callable $action, array $constraints = [], ?string $name = null)
 * @method patch(string $route, string|callable $action, array $constraints = [], ?string $name = null)
 * @method head(string $route, string|callable $action, array $constraints = [], ?string $name = null)
 * @method options(string $route, string|callable $action, array $constraints = [], ?string $name = null)
 */
class Router
{
    private array $rawRoutes = [];
    private ?array $routesTree = null;
    private array $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];
    protected string $currentGroupPrefix = '';
    private array $namedRoutes = [];
    /** Reverse index: route pattern → name, for O(1) lookup in RouteMatchMiddleware. */
    private array $routeNameIndex = [];

    /**
     * Add new route to list of available routes
     *
     * @param string|array $method
     * @param string $route
     * @param callable|string $action
     * @param array $constraints
     * @param string|null $name
     * @return Router
     * @throws \Exception
     */
    public function addRoute($method, string $route, callable|string $action, array $constraints = [], ?string $name = null): Router
    {
        $method = (array)$method;
        $route = $this->currentGroupPrefix . $route;

        // Guard against path traversal in route definitions.
        if (str_contains($route, '..')) {
            throw new InvalidRouteException("Route contains path traversal sequence: '{$route}'");
        }

        $validMethods = array_merge($this->allowedMethods, ['ANY']);
        $methodsMap = [];
        if (array_diff($method, $validMethods)) {
            throw new InvalidRouteException('Method(s): ' . implode(', ', $method) . ' is not valid');
        }
        array_map(function (string $method) use ($action, &$methodsMap) {
            $methodsMap[$method] = $action;
        }, $method === ['ANY'] ? $this->allowedMethods : $method);

        $this->rawRoutes[] = ['route' => $route, 'method' => $methodsMap, 'constraints' => $constraints];

        // Eagerly validate the route pattern (detects empty/duplicate param names immediately).
        $this->parseUri($route);

        if ($name !== null) {
            $this->namedRoutes[$name] = [
                'route' => $route,
                'action' => $action,
                'constraints' => $constraints,
            ];
            // Populate reverse index so RouteMatchMiddleware can look up names in O(1).
            $this->routeNameIndex[$route] = $name;
        }

        return $this;
    }

    /**
     * Create a route group with a common prefix.
     *
     * All routes created in the passed callback will have the given group prefix prepended.
     */
    public function addGroup(string $prefix, callable $callback): void
    {
        $previousGroupPrefix = $this->currentGroupPrefix;
        $this->currentGroupPrefix = $previousGroupPrefix . $prefix;
        $callback($this);
        $this->currentGroupPrefix = $previousGroupPrefix;
    }

    /**
     * @param string $param
     * @param string $value
     * @param mixed $constraint
     * @return Typed|null
     */
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
     * Find route in route tree structure.
     *
     * @param string $method
     * @param string $uri
     * @return array
     * @throws \Exception
     */
    public function findRoute(string $method, string $uri): array
    {
        if ($this->routesTree == null) {
            $this->routesTree = $this->parseRoutes($this->rawRoutes);
        }
        $search = $this->parseUri($uri);
        $node = $this->routesTree;
        $params = [];
        //loop every segment in request url, compare it, collect parameters names and values
        foreach ($search as $v) {
            if (isset($node[$v['use']])) {
                $node = $node[$v['use']];
            } elseif (isset($node['*'])
                && ($val = $this->parseValue($node['*']['name'], $v['name'],
                    $node['*']['constraints'][$node['*']['name']] ?? null))
            ) {
                $node = $node['*'];
                $params[$node['name']] = $val;
            } elseif (isset($node['?'])
                && ($val = $this->parseValue($node['?']['name'], $v['name'],
                    $node['?']['constraints'][$node['?']['name']] ?? null))
            ) {
                $node = $node['?'];
                $params[$node['name']] = $val;
            } else {
                throw new NotFoundException('Route for uri: ' . $uri . ' was not found');
            }
        }
        //check for route with optional parameters that are not in request url until valid action is found
        while (!isset($node['exec']) && isset($node['?'])) {
            $node = $node['?'];
        }
        if (isset($node['exec'])) {
            if (isset($node['exec']['method'][$method]) || isset($node['exec']['method']['ANY'])) {
                return [
                    'route' => $node['exec']['route'],
                    'method' => $method,
                    'action' => $node['exec']['method'][$method],
                    'params' => $params
                ];
            } elseif ($method === 'HEAD' && isset($node['exec']['method']['GET'])) {
                // fallback to matching an available GET route for a given resource
                return [
                    'route' => $node['exec']['route'],
                    'method' => $method,
                    'action' => $node['exec']['method']['GET'],
                    'params' => $params
                ];
            } else {
                throw new MethodNotAllowedException('Method: ' . $method . ' is not allowed for this route', $node['exec']['method']);
            }
        }
        throw new NotFoundException('Route for uri: ' . $uri . ' was not found');
    }

    /**
     * Get routes tree structure. Can be cached and later loaded using load() method
     * @return array|null
     */
    public function dump(): ?array
    {
        if ($this->routesTree == null) {
            $this->routesTree = $this->parseRoutes($this->rawRoutes);
        }
        return $this->routesTree;
    }

    /**
     * Load routes tree structure that was taken from dump() method
     * This method will overwrite anny previously added routes.
     * @param array $routes
     * @return Router
     */
    public function load(array $routes): Router
    {
        $this->routesTree = $routes;
        return $this;
    }

    /**
     * Parse route structure and extract dynamic and optional parts.
     *
     * @param string $route
     * @return array
     * @throws InvalidRouteException
     */
    protected function parseUri(string $route): array
    {
        $chunks = explode('/', '/' . trim($route, '/'));
        $chunks[0] = '/';
        $parsed = [];
        $seenParams = [];

        foreach ($chunks as $chunk) {
            if (strpos($chunk, '{') === 0) {
                if (strpos($chunk, '?}', -2) !== false) { // Optional dynamic
                    $name = mb_substr($chunk, 1, -2);
                    if ($name === '') {
                        throw new InvalidRouteException("Route '{$route}' contains a parameter with an empty name");
                    }
                    if (isset($seenParams[$name])) {
                        throw new InvalidRouteException("Route '{$route}' contains duplicate parameter name '{$name}'");
                    }
                    $seenParams[$name] = true;
                    $parsed[] = ['name' => $name, 'use' => '?'];
                } elseif (strpos($chunk, '}', -1) !== false) { // Mandatory dynamic
                    $name = mb_substr($chunk, 1, -1);
                    if ($name === '') {
                        throw new InvalidRouteException("Route '{$route}' contains a parameter with an empty name");
                    }
                    if (isset($seenParams[$name])) {
                        throw new InvalidRouteException("Route '{$route}' contains duplicate parameter name '{$name}'");
                    }
                    $seenParams[$name] = true;
                    $parsed[] = ['name' => $name, 'use' => '*'];
                } else { // literal
                    $parsed[] = ['name' => $chunk, 'use' => $chunk];
                }
            } else { // literal
                $parsed[] = ['name' => $chunk, 'use' => $chunk];
            }
        }
        return $parsed;
    }

    /**
     * Build tree structure from all routes.
     *
     * @param $routes
     * @return array
     */
    protected function parseRoutes($routes): array
    {
        $tree = [];
        foreach ($routes as $route) {
            $node = &$tree;
            $segments = $this->parseUri($route['route']);
            foreach ($segments as $segment) {
                if (!isset($node[$segment['use']])) {
                    $node[$segment['use']] = ['name' => $segment['name']];
                }
                // Store constraints on parameter nodes so they're available during matching
                if ($segment['use'] === '*' || $segment['use'] === '?') {
                    $node[$segment['use']]['constraints'] = $route['constraints'];
                }
                $node = &$node[$segment['use']];
            }
            //node 'exec' can exist only if a route is already added.
            //this happens when a route is added more than once with different methods.
            if (isset($node['exec'])) {
                $node['exec']['method'] = array_merge($node['exec']['method'], $route['method']);
            } else {
                $node['exec'] = [
                    'route' => $route['route'],
                    'method' => $route['method']
                ];
            }
            if (isset($segment['name'])) {
                $node['name'] = $segment['name'];
            }
        }
        return $tree;
    }

    /**
     * @param string $method
     * @param array $params
     * @return $this
     */
    public function __call(string $method, array $params): Router
    {
        $method = strtoupper($method);
        if (!in_array($method, $this->allowedMethods)) {
            throw new \BadMethodCallException('Call to undefined method');
        }

        // Support name parameter: route($path, $action, $constraints = [], $name = null)
        $route = $params[0] ?? '';
        $action = $params[1] ?? '';
        $constraints = $params[2] ?? [];
        $name = $params[3] ?? null;

        return $this->addRoute($method, $route, $action, $constraints, $name);
    }

    /**
     * Generate URL from named route.
     *
     * @param string $name
     * @param array $parameters
     * @return string
     * @throws InvalidRouteException
     */
    public function generateUrl(string $name, array $parameters = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new InvalidRouteException("Named route '{$name}' not found");
        }

        $route = $this->namedRoutes[$name]['route'];
        $constraints = $this->namedRoutes[$name]['constraints'];

        $url = $route;
        foreach ($parameters as $key => $value) {
            if (isset($constraints[$key])) {
                $constraintClass = $constraints[$key];
                if (!$constraintClass::tryParse($value, $typed)) {
                    throw new InvalidRouteException("Parameter '{$key}' does not match constraint for route '{$name}'");
                }
                $value = $typed->toNative();
            }
            $url = str_replace(['{' . $key . '}', '{' . $key . '?}'], (string)$value, $url);
        }

        // Detect any remaining required parameters.
        // Uses possessive quantifier [^?}]++ to prevent catastrophic backtracking (ReDoS).
        if (preg_match('/\{([^?}]++)\}/', $url, $matches)) {
            throw new InvalidRouteException("Missing required parameter '{$matches[1]}' for route '{$name}'");
        }

        // Remove optional parameters that were not provided.
        // [^?}]++ excludes both '?' and '}' so the possessive quantifier stops before '?}'.
        $url = preg_replace('/\{[^?}]++\?\}/', '', $url);

        // Collapse any double slashes left by optional segment removal.
        $url = preg_replace('#/++#', '/', $url);
        $url = rtrim($url, '/');

        return $url !== '' ? $url : '/';
    }

    /**
     * Look up the name registered for a given route pattern in O(1).
     * Returns null when the pattern has no associated name.
     */
    public function findNameForRoute(string $routePattern): ?string
    {
        return $this->routeNameIndex[$routePattern] ?? null;
    }

    /**
     * Get all named routes
     *
     * @return array
     */
    public function getNamedRoutes(): array
    {
        return $this->namedRoutes;
    }

    /**
     * Check if a named route exists
     *
     * @param string $name
     * @return bool
     */
    public function hasRoute(string $name): bool
    {
        return isset($this->namedRoutes[$name]);
    }

    /**
     * Get raw routes array
     *
     * @return array
     */
    public function getRawRoutes(): array
    {
        return $this->rawRoutes;
    }
}
