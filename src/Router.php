<?php

declare(strict_types=1);

namespace dzentota\Router;

use dzentota\Router\Exception\MethodNotAllowedException;
use dzentota\Router\Exception\NotFoundException;
use dzentota\TypedValue\Typed;

/**
 * @method get(string $route, string $action, array $constraints = [])
 * @method post(string $route, string $action, array $constraints = [])
 * @method put(string $route, string $action, array $constraints = [])
 * @method delete(string $route, string $action, array $constraints = [])
 * @method patch(string $route, string $action, array $constraints = [])
 * @method head(string $route, string $action, array $constraints = [])
 * @method options(string $route, string $action, array $constraints = [])
 */
class Router
{
    private array $rawRoutes = [];
    private ?array $routesTree = null;
    private array $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];
    protected string $currentGroupPrefix = '';

    /**
     * Add new route to list of available routes
     *
     * @param string|array $method
     * @param string $route
     * @param string $action
     * @param array $constraints
     * @return Router
     * @throws \Exception
     */
    public function addRoute($method, string $route, string $action, array $constraints = []): Router
    {
        $method = (array)$method;
        $route = $this->currentGroupPrefix . $route;
        $validMethods = array_merge($this->allowedMethods, ['ANY']);
        $methodsMap = [];
        if (array_diff($method, $validMethods)) {
            throw new \Exception('Method(s):' . implode(', ', $method) . ' is not valid');
        }
        array_map(function (string $method) use ($action, &$methodsMap) {
            $methodsMap[$method] = $action;
        }, $method === ['ANY'] ? $this->allowedMethods : $method);

        $this->rawRoutes[] = ['route' => $route, 'method' => $methodsMap, 'constraints' => $constraints];
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
     * @param $constraint
     * @return Typed|null
     */
    private function parseValue(string $param, string $value, $constraint): ?Typed
    {
        if (empty($constraint)) {
            throw new \DomainException("The parameter '{$param}' has no constraints");
        }
        /**
         * @var Typed $constraint
         */
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
                    $node['*']['exec']['constraints'][$node['*']['name']] ?? null))
            ) {
                $node = $node['*'];
                $params[$node['name']] = $val;
            } elseif (isset($node['?'])
                && ($val = $this->parseValue($node['?']['name'], $v['name'],
                    $node['?']['exec']['constraints'][$node['?']['name']] ?? null))
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
     * Parse route structure and extract dynamic and optional parts
     *
     * @param string $route
     * @return array
     */
    protected function parseUri(string $route): array
    {
        $chunks = explode('/', '/' . trim($route, '/'));
        $chunks[0] = '/';
        $parsed = [];
        //check for dynamic and optional parameters
        foreach ($chunks as $chunk) {
            if (strpos($chunk, '{') === 0) {
                if (strpos($chunk, '?}', -2) !== false) {// Optional dynamic
                    $parsed[] = ['name' => mb_substr($chunk, 1, -2), 'use' => '?'];
                } elseif (strpos($chunk, '}', -1) !== false) { // Mandatory dynamic
                    $parsed[] = ['name' => mb_substr($chunk, 1, -1), 'use' => '*'];
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
            foreach ($this->parseUri($route['route']) as $segment) {
                if (!isset($node[$segment['use']])) {
                    $node[$segment['use']] = ['name' => $segment['name']];
                }
                $node = &$node[$segment['use']];
            }
            //node 'exec' can exist only if a route is already added.
            //this happens when a route is added more than once with different methods.
            if (isset($node['exec'])) {
                $node['exec']['method'] = array_merge($node['exec']['method'], $route['method']);
            } else {
                $node['exec'] = $route;
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

        array_unshift($params, $method);
        call_user_func_array(array($this, 'addRoute'), $params);
        return $this;
    }
}
