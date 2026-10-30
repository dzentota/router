<?php
declare(strict_types=1);

namespace dzentota\Router\Test;

use dzentota\Router\Middleware\RouteDispatchMiddleware;
use dzentota\Router\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ExceptionPropagationTest extends TestCase
{
    private function throwingRequest(): ServerRequestInterface
    {
        return (new ServerRequest('GET', '/x'))
            ->withAttribute('route_matched', true)
            ->withAttribute('route_handler', function () { throw new \RuntimeException('boom'); });
    }

    private function next(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                return (new Psr17Factory())->createResponse(204);
            }
        };
    }

    public function testDefaultSwallowsInto500(): void
    {
        $r = (new RouteDispatchMiddleware())->process($this->throwingRequest(), $this->next());
        $this->assertSame(500, $r->getStatusCode());
    }

    public function testPropagatesWhenEnabled(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');
        (new RouteDispatchMiddleware(null, null, true))->process($this->throwingRequest(), $this->next());
    }

    public function testRouterDispatchDefaultReturns500(): void
    {
        $router = new Router();
        $router->get('/x', function () { throw new \RuntimeException('boom'); });
        $this->assertSame(500, $router->dispatch('/x', 'GET')->getStatusCode());
    }

    public function testRouterDispatchCanPropagate(): void
    {
        $router = new Router();
        $router->setPropagateExceptions(true);
        $router->get('/x', function () { throw new \RuntimeException('boom'); });
        $this->expectException(\RuntimeException::class);
        $router->dispatch('/x', 'GET');
    }

    public function testRouterConstructorFlagPropagates(): void
    {
        $router = new Router(null, null, true);
        $router->get('/x', function () { throw new \RuntimeException('boom'); });
        $this->expectException(\RuntimeException::class);
        $router->dispatch('/x', 'GET');
    }
}
