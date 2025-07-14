<?php
declare(strict_types=1);

namespace dzentota\Router\Middleware;

use dzentota\Router\Router;
use dzentota\Router\Exception\NotFoundException;
use dzentota\Router\Exception\MethodNotAllowedException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * Route Match Middleware
 * 
 * Matches incoming requests against defined routes and adds
 * route information to request attributes for downstream processing.
 */
class RouteMatchMiddleware implements MiddlewareInterface
{
    protected Router $router;
    private Psr17Factory $responseFactory;

    public function __construct(Router $router)
    {
        $this->router = $router;
        $this->responseFactory = new Psr17Factory();
    }

    /**
     * Process request and match against routes
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = $request->getMethod();
        $uri = $request->getUri();
        $path = $uri->getPath();

        try {
            // Attempt to find matching route
            $routeData = $this->router->findRoute($method, $path);
            
            if ($routeData) {
                // Add route information to request attributes
                $request = $this->addRouteAttributes($request, $routeData);
            } else {
                // No route found - this will be handled by RouteDispatchMiddleware
                $request = $request->withAttribute('route_not_found', true);
            }
            
        } catch (MethodNotAllowedException $e) {
            // Method not allowed - add allowed methods to request
            $request = $request
                ->withAttribute('route_method_not_allowed', true)
                ->withAttribute('allowed_methods', $e->getAllowedMethods());
                
        } catch (NotFoundException $e) {
            // Route not found
            $request = $request->withAttribute('route_not_found', true);
        }

        return $handler->handle($request);
    }

    /**
     * Add route data to request attributes
     */
    protected function addRouteAttributes(ServerRequestInterface $request, array $routeData): ServerRequestInterface
    {
        // Add route action/handler
        $request = $request->withAttribute('route_handler', $routeData['action']);
        
        // Add route parameters (if any)
        if (isset($routeData['params']) && is_array($routeData['params'])) {
            foreach ($routeData['params'] as $name => $value) {
                $request = $request->withAttribute($name, $value);
            }
            $request = $request->withAttribute('route_params', $routeData['params']);
        }
        
        // Add route constraints (if any)
        if (isset($routeData['constraints'])) {
            $request = $request->withAttribute('route_constraints', $routeData['constraints']);
        }
        
        // Add route name (if it's a named route)
        // Look up route name from named routes collection
        $routeName = $this->findRouteName($routeData);
        if ($routeName) {
            $request = $request->withAttribute('route_name', $routeName);
        }
        
        // Add original route pattern
        if (isset($routeData['route'])) {
            $request = $request->withAttribute('route_pattern', $routeData['route']);
        }

        // Mark that route was successfully matched
        $request = $request->withAttribute('route_matched', true);
        
        return $request;
    }

    /**
     * Create 404 Not Found response
     */
    public function createNotFoundResponse(): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(404);
        
        $errorBody = json_encode([
            'error' => 'Not Found',
            'message' => 'The requested resource was not found',
            'timestamp' => date('c')
        ]);
        
        $response->getBody()->write($errorBody);
        
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Create 405 Method Not Allowed response
     */
    public function createMethodNotAllowedResponse(array $allowedMethods): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(405);
        
        $errorBody = json_encode([
            'error' => 'Method Not Allowed',
            'message' => 'The request method is not allowed for this resource',
            'allowed_methods' => $allowedMethods,
            'timestamp' => date('c')
        ]);
        
        $response->getBody()->write($errorBody);
        
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Allow', implode(', ', $allowedMethods));
    }
    
    /**
     * Find route name by matching route pattern and action
     */
    private function findRouteName(array $routeData): ?string
    {
        $namedRoutes = $this->router->getNamedRoutes();
        
        foreach ($namedRoutes as $name => $namedRoute) {
            if ($namedRoute['route'] === $routeData['route'] && $namedRoute['action'] === $routeData['action']) {
                return $name;
            }
        }
        
        return null;
    }
} 