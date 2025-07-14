<?php
declare(strict_types=1);

namespace dzentota\Router\Test;

use PHPUnit\Framework\TestCase;
use dzentota\Router\Middleware\MiddlewareStack;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Nyholm\Psr7\Factory\Psr17Factory;

class MiddlewareStackTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    public function testEmptyStack(): void
    {
        $finalHandler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = (new Psr17Factory())->createResponse(200);
                $response->getBody()->write('Final Handler');
                return $response;
            }
        };

        // Create empty stack
        $stack = MiddlewareStack::create($finalHandler);

        $request = new ServerRequest('GET', '/test');
        $response = $stack->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Final Handler', (string)$response->getBody());
    }

    public function testMiddlewareOrder(): void
    {
        $finalHandler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = (new Psr17Factory())->createResponse(200);
                $response->getBody()->write($request->getAttribute('order', ''));
                return $response;
            }
        };

        $middleware1 = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $request = $request->withAttribute('order', '1');
                return $handler->handle($request);
            }
        };

        $middleware2 = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $request = $request->withAttribute('order',
                    $request->getAttribute('order', '') . '2');
                return $handler->handle($request);
            }
        };

        $middleware3 = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $request = $request->withAttribute('order',
                    $request->getAttribute('order', '') . '3');
                return $handler->handle($request);
            }
        };

        // Stack should process middleware in order: 1, 2, 3
        $stack = MiddlewareStack::create($finalHandler, $middleware1, $middleware2, $middleware3);

        $request = new ServerRequest('GET', '/test');
        $response = $stack->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('123', (string)$response->getBody());
    }

    public function testMiddlewareShortCircuit(): void
    {
        $finalHandler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Psr17Factory())->createResponse(200)
                    ->withHeader('X-Handler', 'Final');
            }
        };

        $middleware1 = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }
        };

        $middleware2 = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                // This middleware short-circuits and doesn't call the next handler
                return (new Psr17Factory())->createResponse(403)
                    ->withHeader('X-Handler', 'Middleware2');
            }
        };

        $middleware3 = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }
        };

        // Stack should stop at middleware2
        $stack = MiddlewareStack::create($finalHandler, $middleware1, $middleware2, $middleware3);

        $request = new ServerRequest('GET', '/test');
        $response = $stack->handle($request);

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertEquals('Middleware2', $response->getHeaderLine('X-Handler'));
    }

    public function testResponseModification(): void
    {
        $finalHandler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = (new Psr17Factory())->createResponse(200);
                $response->getBody()->write('Original');
                return $response;
            }
        };

        $middleware1 = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $response = $handler->handle($request);
                $response->getBody()->write('-Modified1');
                return $response;
            }
        };

        $middleware2 = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $response = $handler->handle($request);
                $response->getBody()->write('-Modified2');
                return $response->withHeader('X-Modified', 'Yes');
            }
        };

        // Middleware stack processes inwards, then unwinds outwards
        $stack = MiddlewareStack::create($finalHandler, $middleware1, $middleware2);

        $request = new ServerRequest('GET', '/test');
        $response = $stack->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Original-Modified2-Modified1', (string)$response->getBody());
        $this->assertEquals('Yes', $response->getHeaderLine('X-Modified'));
    }

    public function testAddMiddleware(): void
    {
        $finalHandler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = (new Psr17Factory())->createResponse(200);
                $response->getBody()->write('Final');
                return $response;
            }
        };

        // Create stack with one middleware
        $stack = MiddlewareStack::create($finalHandler);

        // Add more middleware dynamically
        $middleware = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $response = $handler->handle($request);
                return $response->withHeader('X-Dynamic', 'Added');
            }
        };

        $stack->add($middleware);

        $request = new ServerRequest('GET', '/test');
        $response = $stack->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Final', (string)$response->getBody());
        $this->assertEquals('Added', $response->getHeaderLine('X-Dynamic'));
    }
}
