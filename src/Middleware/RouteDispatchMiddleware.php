<?php
declare(strict_types=1);

namespace dzentota\Router\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Container\ContainerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * Route Dispatch Middleware
 * 
 * Executes the matched route handler. Should be placed after RouteMatchMiddleware
 * in the middleware stack. Supports various handler types and dependency injection.
 */
class RouteDispatchMiddleware implements MiddlewareInterface
{
    private ?ContainerInterface $container;
    private Psr17Factory $responseFactory;

    public function __construct(?ContainerInterface $container = null)
    {
        $this->container = $container;
        $this->responseFactory = new Psr17Factory();
    }

    /**
     * Process request and dispatch to route handler
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Check if route was matched
        if (!$request->getAttribute('route_matched', false)) {
            return $this->handleUnmatched($request, $handler);
        }

        // Get route handler from request attributes
        $routeHandler = $request->getAttribute('route_handler');
        
        if ($routeHandler === null) {
            return $this->createErrorResponse(500, 'Route handler not found');
        }

        try {
            // Resolve and execute the handler
            $response = $this->executeHandler($routeHandler, $request);
            
            // Ensure we return a valid ResponseInterface
            if (!$response instanceof ResponseInterface) {
                $response = $this->createResponseFromContent($response);
            }
            
            return $response;
            
        } catch (\Throwable $e) {
            // Log error and return 500 response
            error_log("Route dispatch error: " . $e->getMessage());
            return $this->createErrorResponse(500, 'Internal server error');
        }
    }

    /**
     * Handle unmatched routes (404/405)
     */
    protected function handleUnmatched(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Check if method not allowed
        if ($request->getAttribute('route_method_not_allowed', false)) {
            $allowedMethods = $request->getAttribute('allowed_methods', []);
            return $this->createMethodNotAllowedResponse($allowedMethods);
        }
        
        // Check if route not found
        if ($request->getAttribute('route_not_found', false)) {
            return $this->createNotFoundResponse();
        }
        
        // Continue to next middleware if no routing attributes
        return $handler->handle($request);
    }

    /**
     * Execute the route handler
     */
    protected function executeHandler($handler, ServerRequestInterface $request)
    {
        // Handle different types of handlers
        
        // 1. Callable/Closure
        if (is_callable($handler)) {
            return $this->executeCallable($handler, $request);
        }
        
        // 2. Controller@method string
        if (is_string($handler) && str_contains($handler, '@')) {
            return $this->executeControllerMethod($handler, $request);
        }
        
        // 3. Class name (invokable controller)
        if (is_string($handler) && class_exists($handler)) {
            return $this->executeInvokableController($handler, $request);
        }
        
        // 4. Array [controller, method]
        if (is_array($handler) && count($handler) === 2) {
            return $this->executeControllerArray($handler, $request);
        }
        
        throw new \InvalidArgumentException('Invalid route handler type');
    }

    /**
     * Execute callable handler
     */
    private function executeCallable(callable $handler, ServerRequestInterface $request)
    {
        $params = $this->resolveParameters($handler, $request);
        return call_user_func_array($handler, $params);
    }

    /**
     * Execute controller@method handler
     */
    private function executeControllerMethod(string $handler, ServerRequestInterface $request)
    {
        [$controllerClass, $method] = explode('@', $handler, 2);
        
        $controller = $this->resolveController($controllerClass);
        
        if (!method_exists($controller, $method)) {
            throw new \BadMethodCallException("Method {$method} not found in {$controllerClass}");
        }
        
        $params = $this->resolveParameters([$controller, $method], $request);
        return call_user_func_array([$controller, $method], $params);
    }

    /**
     * Execute invokable controller
     */
    private function executeInvokableController(string $controllerClass, ServerRequestInterface $request)
    {
        $controller = $this->resolveController($controllerClass);
        
        if (!method_exists($controller, '__invoke')) {
            throw new \BadMethodCallException("Controller {$controllerClass} is not invokable");
        }
        
        $params = $this->resolveParameters([$controller, '__invoke'], $request);
        return call_user_func_array($controller, $params);
    }

    /**
     * Execute controller array handler
     */
    private function executeControllerArray(array $handler, ServerRequestInterface $request)
    {
        [$controllerClass, $method] = $handler;
        
        $controller = is_object($controllerClass) ? $controllerClass : $this->resolveController($controllerClass);
        
        if (!method_exists($controller, $method)) {
            $className = is_object($controller) ? get_class($controller) : $controllerClass;
            throw new \BadMethodCallException("Method {$method} not found in {$className}");
        }
        
        $params = $this->resolveParameters([$controller, $method], $request);
        return call_user_func_array([$controller, $method], $params);
    }

    /**
     * Resolve controller from container or instantiate directly
     */
    private function resolveController(string $controllerClass): object
    {
        // Try to resolve from container first
        if ($this->container && $this->container->has($controllerClass)) {
            return $this->container->get($controllerClass);
        }
        
        // Fallback to direct instantiation
        if (!class_exists($controllerClass)) {
            throw new \InvalidArgumentException("Controller class {$controllerClass} not found");
        }
        
        return new $controllerClass();
    }

    /**
     * Resolve method parameters using dependency injection
     */
    private function resolveParameters(callable $handler, ServerRequestInterface $request): array
    {
        $reflection = new \ReflectionFunction($handler);
        if (is_array($handler)) {
            $reflection = new \ReflectionMethod($handler[0], $handler[1]);
        }
        
        $parameters = [];
        $routeParams = $request->getAttribute('route_params', []);
        
        foreach ($reflection->getParameters() as $param) {
            $paramName = $param->getName();
            $paramType = $param->getType();
            
            // 1. Try to inject PSR-7 request
            if ($paramType && $paramType->getName() === ServerRequestInterface::class) {
                $parameters[] = $request;
                continue;
            }
            
            // 2. Try route parameters
            if (isset($routeParams[$paramName])) {
                $parameters[] = $routeParams[$paramName];
                continue;
            }
            
            // 3. Try request attributes
            if ($request->getAttribute($paramName) !== null) {
                $parameters[] = $request->getAttribute($paramName);
                continue;
            }
            
            // 4. Try container resolution (if type hint available)
            if ($paramType && $this->container && $this->container->has($paramType->getName())) {
                $parameters[] = $this->container->get($paramType->getName());
                continue;
            }
            
            // 5. Use default value if available
            if ($param->isDefaultValueAvailable()) {
                $parameters[] = $param->getDefaultValue();
                continue;
            }
            
            // 6. Allow nullable parameters
            if ($param->allowsNull()) {
                $parameters[] = null;
                continue;
            }
            
            throw new \InvalidArgumentException("Cannot resolve parameter '{$paramName}' for route handler");
        }
        
        return $parameters;
    }

    /**
     * Create response from various content types
     */
    protected function createResponseFromContent($content): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(200);
        
        if (is_string($content)) {
            $response->getBody()->write($content);
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }
        
        if (is_array($content) || is_object($content)) {
            $json = json_encode($content);
            $response->getBody()->write($json);
            return $response->withHeader('Content-Type', 'application/json');
        }
        
        $response->getBody()->write((string)$content);
        return $response;
    }

    /**
     * Create 404 Not Found response
     */
    protected function createNotFoundResponse(): ResponseInterface
    {
        return $this->createErrorResponse(404, 'Not Found');
    }

    /**
     * Create 405 Method Not Allowed response
     */
    protected function createMethodNotAllowedResponse(array $allowedMethods): ResponseInterface
    {
        $response = $this->createErrorResponse(405, 'Method Not Allowed');
        return $response->withHeader('Allow', implode(', ', $allowedMethods));
    }

    /**
     * Create generic error response
     */
    protected function createErrorResponse(int $status, string $message): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status);
        
        $errorBody = json_encode([
            'error' => $message,
            'status' => $status,
            'timestamp' => date('c')
        ]);
        
        $response->getBody()->write($errorBody);
        return $response->withHeader('Content-Type', 'application/json');
    }
} 