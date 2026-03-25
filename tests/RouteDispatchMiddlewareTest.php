<?php

declare(strict_types=1);

namespace dzentota\Router\Test;

use dzentota\Router\Middleware\RouteDispatchMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RouteDispatchMiddlewareTest extends TestCase
{
    private Psr17Factory $factory;
    private RequestHandlerInterface $fallback;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->fallback = new class($this->factory) implements RequestHandlerInterface {
            public function __construct(private Psr17Factory $f) {}
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                return $this->f->createResponse(200)->withHeader('X-Fallback', 'true');
            }
        };
    }

    // -------------------------------------------------------------------------
    // Unmatched request handling
    // -------------------------------------------------------------------------

    public function testPassesThroughWhenRouteNotMatched(): void
    {
        $mw      = new RouteDispatchMiddleware();
        $request = new ServerRequest('GET', '/');

        $response = $mw->process($request, $this->fallback);

        self::assertSame('true', $response->getHeaderLine('X-Fallback'));
    }

    public function testReturns405WhenMethodNotAllowed(): void
    {
        $mw      = new RouteDispatchMiddleware();
        $request = (new ServerRequest('POST', '/'))
            ->withAttribute('route_method_not_allowed', true)
            ->withAttribute('allowed_methods', ['GET', 'HEAD']);

        $response = $mw->process($request, $this->fallback);

        self::assertSame(405, $response->getStatusCode());
        self::assertStringContainsString('GET', $response->getHeaderLine('Allow'));
    }

    public function testReturns404WhenRouteNotFound(): void
    {
        $mw      = new RouteDispatchMiddleware();
        $request = (new ServerRequest('GET', '/missing'))
            ->withAttribute('route_not_found', true);

        $response = $mw->process($request, $this->fallback);

        self::assertSame(404, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Callable handler
    // -------------------------------------------------------------------------

    public function testDispatchesToClosure(): void
    {
        $mw = new RouteDispatchMiddleware();

        $handler = function (): ResponseInterface {
            $f = new Psr17Factory();
            return $f->createResponse(201);
        };

        $request = (new ServerRequest('GET', '/'))
            ->withAttribute('route_matched', true)
            ->withAttribute('route_handler', $handler);

        $response = $mw->process($request, $this->fallback);

        self::assertSame(201, $response->getStatusCode());
    }

    public function testClosureReceivesPsr7Request(): void
    {
        $mw = new RouteDispatchMiddleware();

        $captured = null;
        $handler  = function (ServerRequestInterface $req) use (&$captured): string {
            $captured = $req;
            return 'ok';
        };

        $request = (new ServerRequest('GET', '/'))
            ->withAttribute('route_matched', true)
            ->withAttribute('route_handler', $handler);

        $mw->process($request, $this->fallback);

        self::assertSame($request, $captured);
    }

    // -------------------------------------------------------------------------
    // Controller@method handler
    // -------------------------------------------------------------------------

    public function testDispatchesToControllerAtMethod(): void
    {
        $mw = new RouteDispatchMiddleware();

        $request = (new ServerRequest('GET', '/'))
            ->withAttribute('route_matched', true)
            ->withAttribute('route_handler', FakeController::class . '@handle');

        $response = $mw->process($request, $this->fallback);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('FakeController::handle', $response->getHeaderLine('X-Handler'));
    }

    // -------------------------------------------------------------------------
    // Invokable controller
    // -------------------------------------------------------------------------

    public function testDispatchesToInvokableController(): void
    {
        $mw = new RouteDispatchMiddleware();

        $request = (new ServerRequest('GET', '/'))
            ->withAttribute('route_matched', true)
            ->withAttribute('route_handler', FakeInvokable::class);

        $response = $mw->process($request, $this->fallback);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('FakeInvokable::__invoke', $response->getHeaderLine('X-Handler'));
    }

    // -------------------------------------------------------------------------
    // Array [class, method] handler
    // -------------------------------------------------------------------------

    public function testDispatchesToControllerArray(): void
    {
        $mw = new RouteDispatchMiddleware();

        $request = (new ServerRequest('GET', '/'))
            ->withAttribute('route_matched', true)
            ->withAttribute('route_handler', [FakeController::class, 'handle']);

        $response = $mw->process($request, $this->fallback);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('FakeController::handle', $response->getHeaderLine('X-Handler'));
    }

    public function testDispatchesToObjectMethodArray(): void
    {
        $mw = new RouteDispatchMiddleware();

        $request = (new ServerRequest('GET', '/'))
            ->withAttribute('route_matched', true)
            ->withAttribute('route_handler', [new FakeController(), 'handle']);

        $response = $mw->process($request, $this->fallback);

        self::assertSame(200, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Route parameter injection
    // -------------------------------------------------------------------------

    public function testRouteParamsAreInjectedByName(): void
    {
        $mw = new RouteDispatchMiddleware();

        $handler = function (string $id): string {
            return "id={$id}";
        };

        $request = (new ServerRequest('GET', '/users/42'))
            ->withAttribute('route_matched', true)
            ->withAttribute('route_handler', $handler)
            ->withAttribute('route_params', ['id' => '42']);

        $response = $mw->process($request, $this->fallback);

        self::assertStringContainsString('id=42', (string)$response->getBody());
    }

    // -------------------------------------------------------------------------
    // Invalid handler — must return 500, not throw
    // -------------------------------------------------------------------------

    public function testInvalidHandlerReturns500(): void
    {
        $mw = new RouteDispatchMiddleware();

        $request = (new ServerRequest('GET', '/'))
            ->withAttribute('route_matched', true)
            ->withAttribute('route_handler', new \stdClass()); // not a valid handler

        $response = $mw->process($request, $this->fallback);

        self::assertSame(500, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Reflection caching — same middleware instance reuses cache
    // -------------------------------------------------------------------------

    public function testReflectionCacheIsReused(): void
    {
        $mw   = new RouteDispatchMiddleware();
        $calls = 0;

        $handler = function () use (&$calls): string {
            $calls++;
            return 'ok';
        };

        for ($i = 0; $i < 3; $i++) {
            $request = (new ServerRequest('GET', '/'))
                ->withAttribute('route_matched', true)
                ->withAttribute('route_handler', $handler);
            $mw->process($request, $this->fallback);
        }

        // All three invocations must succeed (regression check — not a perf benchmark).
        self::assertSame(3, $calls);
    }

    // -------------------------------------------------------------------------
    // Missing route_handler attribute
    // -------------------------------------------------------------------------

    public function testMissingHandlerAttributeReturns500(): void
    {
        $mw = new RouteDispatchMiddleware();

        $request = (new ServerRequest('GET', '/'))
            ->withAttribute('route_matched', true);
        // route_handler intentionally absent

        $response = $mw->process($request, $this->fallback);

        self::assertSame(500, $response->getStatusCode());
    }
}

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

class FakeController
{
    public function handle(): ResponseInterface
    {
        $f = new Psr17Factory();
        return $f->createResponse(200)->withHeader('X-Handler', 'FakeController::handle');
    }
}

class FakeInvokable
{
    public function __invoke(): ResponseInterface
    {
        $f = new Psr17Factory();
        return $f->createResponse(200)->withHeader('X-Handler', 'FakeInvokable::__invoke');
    }
}
