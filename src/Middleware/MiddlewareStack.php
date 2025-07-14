<?php
declare(strict_types=1);

namespace dzentota\Router\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware Stack
 * 
 * Helper class for composing and executing middleware stacks
 */
class MiddlewareStack implements RequestHandlerInterface
{
    private array $middlewares = [];
    private RequestHandlerInterface $finalHandler;

    public function __construct(RequestHandlerInterface $finalHandler)
    {
        $this->finalHandler = $finalHandler;
    }

    public function add(MiddlewareInterface $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->processMiddleware($request, 0);
    }

    public function processMiddleware(ServerRequestInterface $request, int $index): ResponseInterface
    {
        if ($index >= count($this->middlewares)) {
            return $this->finalHandler->handle($request);
        }

        $middleware = $this->middlewares[$index];
        $nextHandler = new class($this, $index + 1) implements RequestHandlerInterface {
            private MiddlewareStack $stack;
            private int $nextIndex;

            public function __construct(MiddlewareStack $stack, int $nextIndex) {
                $this->stack = $stack;
                $this->nextIndex = $nextIndex;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface {
                return $this->stack->processMiddleware($request, $this->nextIndex);
            }
        };

        return $middleware->process($request, $nextHandler);
    }

    public static function create(RequestHandlerInterface $finalHandler, MiddlewareInterface ...$middlewares): self
    {
        $stack = new self($finalHandler);
        foreach ($middlewares as $middleware) {
            $stack->add($middleware);
        }
        return $stack;
    }
}