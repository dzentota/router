<?php

declare(strict_types=1);

namespace dzentota\Router\Test;

use dzentota\Router\Router;
use dzentota\TypedValue\Typed;
use dzentota\TypedValue\TypedValue;
use dzentota\TypedValue\ValidationResult;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Tests for Router::dispatch(), Router::middleware(), Route::middleware(),
 * and addGroup() group middleware.
 */
class RouterDispatchTest extends TestCase
{
    // =========================================================================
    // Helpers — simple middleware stubs
    // =========================================================================

    /** Records call order and passes through. */
    private function makeRecordingMiddleware(array &$log, string $tag): MiddlewareInterface
    {
        return new class($log, $tag) implements MiddlewareInterface {
            public function __construct(private array &$log, private string $tag) {}
            public function process(ServerRequestInterface $req, RequestHandlerInterface $next): ResponseInterface
            {
                $this->log[] = 'before:' . $this->tag;
                $response    = $next->handle($req);
                $this->log[] = 'after:' . $this->tag;
                return $response;
            }
        };
    }

    /** Adds a response header; useful for asserting which middleware ran. */
    private function makeHeaderMiddleware(string $header, string $value): MiddlewareInterface
    {
        return new class($header, $value) implements MiddlewareInterface {
            public function __construct(private string $h, private string $v) {}
            public function process(ServerRequestInterface $req, RequestHandlerInterface $next): ResponseInterface
            {
                return $next->handle($req)->withHeader($this->h, $this->v);
            }
        };
    }

    /** Immediately returns a 403 without calling next — useful to assert middleware ran. */
    private function makeBlockingMiddleware(): MiddlewareInterface
    {
        return new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $req, RequestHandlerInterface $next): ResponseInterface
            {
                $factory  = new \Nyholm\Psr7\Factory\Psr17Factory();
                $response = $factory->createResponse(403);
                $response->getBody()->write('blocked');
                return $response;
            }
        };
    }

    // =========================================================================
    // dispatch() basics
    // =========================================================================

    public function testDispatchReturnsResponseForMatchedRoute(): void
    {
        $router = new Router();
        $router->get('/', fn() => 'Hello World');

        $response = $router->dispatch('/', 'GET');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Hello World', (string)$response->getBody());
    }

    public function testDispatchReturns404ForUnknownRoute(): void
    {
        $router   = new Router();
        $response = $router->dispatch('/nope', 'GET');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testDispatchReturns405ForWrongMethod(): void
    {
        $router = new Router();
        $router->get('/data', fn() => 'ok');

        $response = $router->dispatch('/data', 'DELETE');

        self::assertSame(405, $response->getStatusCode());
    }

    public function testDispatchAcceptsPreBuiltRequest(): void
    {
        $router = new Router();
        $router->get('/ping', fn() => 'pong');

        $request  = new ServerRequest('GET', '/ping');
        $response = $router->dispatch('/ping', 'GET', $request);

        self::assertSame(200, $response->getStatusCode());
    }

    // =========================================================================
    // Global middleware via Router::middleware()
    // =========================================================================

    public function testGlobalMiddlewareRunsForEveryRequest(): void
    {
        $router = new Router();
        $router->middleware($this->makeHeaderMiddleware('X-Global', 'yes'));
        $router->get('/', fn() => 'hi');

        $response = $router->dispatch('/', 'GET');

        self::assertSame('yes', $response->getHeaderLine('X-Global'));
    }

    public function testGlobalMiddlewareRunsEvenOn404(): void
    {
        $router = new Router();
        $router->middleware($this->makeHeaderMiddleware('X-Global', 'present'));

        $response = $router->dispatch('/nope', 'GET');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('present', $response->getHeaderLine('X-Global'));
    }

    public function testMultipleGlobalMiddlewareRunInRegistrationOrder(): void
    {
        $log    = [];
        $router = new Router();
        $router->middleware($this->makeRecordingMiddleware($log, 'A'));
        $router->middleware($this->makeRecordingMiddleware($log, 'B'));
        $router->get('/', fn() => 'ok');

        $router->dispatch('/', 'GET');

        self::assertSame(['before:A', 'before:B', 'after:B', 'after:A'], $log);
    }

    public function testGlobalMiddlewareCanShortCircuit(): void
    {
        $router = new Router();
        $router->middleware($this->makeBlockingMiddleware());
        $router->get('/', fn() => 'should not reach');

        $response = $router->dispatch('/', 'GET');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('blocked', (string)$response->getBody());
    }

    // =========================================================================
    // Per-route middleware via Route::middleware()
    // =========================================================================

    public function testRouteMiddlewareRunsOnlyForThatRoute(): void
    {
        $router = new Router();
        $router->get('/protected', fn() => 'secret')
               ->middleware($this->makeHeaderMiddleware('X-Route', 'hit'));
        $router->get('/public', fn() => 'open');

        $protected = $router->dispatch('/protected', 'GET');
        $public    = $router->dispatch('/public',    'GET');

        self::assertSame('hit', $protected->getHeaderLine('X-Route'));
        self::assertSame('',    $public->getHeaderLine('X-Route'));
    }

    public function testRouteMiddlewareCanBlockAccess(): void
    {
        $router = new Router();
        $router->get('/admin', fn() => 'admin data')
               ->middleware($this->makeBlockingMiddleware());

        $response = $router->dispatch('/admin', 'GET');

        self::assertSame(403, $response->getStatusCode());
    }

    public function testMultipleRouteMiddlewareRunInOrder(): void
    {
        $log    = [];
        $router = new Router();
        $router->get('/', fn() => 'ok')
               ->middleware(
                   $this->makeRecordingMiddleware($log, 'R1'),
                   $this->makeRecordingMiddleware($log, 'R2'),
               );

        $router->dispatch('/', 'GET');

        self::assertSame(['before:R1', 'before:R2', 'after:R2', 'after:R1'], $log);
    }

    public function testGlobalMiddlewareRunsBeforeRouteMiddleware(): void
    {
        $log    = [];
        $router = new Router();
        $router->middleware($this->makeRecordingMiddleware($log, 'G'));
        $router->get('/', fn() => 'ok')
               ->middleware($this->makeRecordingMiddleware($log, 'R'));

        $router->dispatch('/', 'GET');

        // Global runs first (before route match), route middleware runs second.
        self::assertSame(['before:G', 'before:R', 'after:R', 'after:G'], $log);
    }

    // =========================================================================
    // Group middleware via addGroup(..., $middleware)
    // =========================================================================

    public function testGroupMiddlewareAppliesToRoutesInGroup(): void
    {
        $router = new Router();
        $router->addGroup('/admin', function (Router $r) {
            $r->get('/dashboard', fn() => 'dash');
        }, [$this->makeHeaderMiddleware('X-Group', 'admin')]);

        $response = $router->dispatch('/admin/dashboard', 'GET');

        self::assertSame('admin', $response->getHeaderLine('X-Group'));
    }

    public function testGroupMiddlewareDoesNotAffectRoutesOutsideGroup(): void
    {
        $router = new Router();
        $router->addGroup('/admin', function (Router $r) {
            $r->get('/dash', fn() => 'dash');
        }, [$this->makeHeaderMiddleware('X-Admin', 'yes')]);
        $router->get('/public', fn() => 'pub');

        $pub = $router->dispatch('/public', 'GET');

        self::assertSame('', $pub->getHeaderLine('X-Admin'));
    }

    public function testGroupMiddlewareRunsBeforeRouteMiddleware(): void
    {
        $log    = [];
        $router = new Router();
        $router->addGroup('/g', function (Router $r) use (&$log) {
            $r->get('/x', fn() => 'ok')
              ->middleware($this->makeRecordingMiddleware($log, 'Route'));
        }, [$this->makeRecordingMiddleware($log, 'Group')]);

        $router->dispatch('/g/x', 'GET');

        self::assertSame(['before:Group', 'before:Route', 'after:Route', 'after:Group'], $log);
    }

    public function testNestedGroupMiddlewareAccumulates(): void
    {
        $log    = [];
        $router = new Router();
        $router->addGroup('/api', function (Router $r) use (&$log) {
            $r->addGroup('/v1', function (Router $r) use (&$log) {
                $r->get('/data', fn() => 'data')
                  ->middleware($this->makeRecordingMiddleware($log, 'Route'));
            }, [$this->makeRecordingMiddleware($log, 'InnerGroup')]);
        }, [$this->makeRecordingMiddleware($log, 'OuterGroup')]);

        $router->dispatch('/api/v1/data', 'GET');

        // Outer group first, inner group second, route third.
        self::assertSame([
            'before:OuterGroup', 'before:InnerGroup', 'before:Route',
            'after:Route', 'after:InnerGroup', 'after:OuterGroup',
        ], $log);
    }

    // =========================================================================
    // Full order: global → group → route
    // =========================================================================

    public function testFullMiddlewareOrderIsGlobalGroupRoute(): void
    {
        $log    = [];
        $router = new Router();
        $router->middleware($this->makeRecordingMiddleware($log, 'Global'));
        $router->addGroup('/g', function (Router $r) use (&$log) {
            $r->get('/x', fn() => 'ok')
              ->middleware($this->makeRecordingMiddleware($log, 'Route'));
        }, [$this->makeRecordingMiddleware($log, 'Group')]);

        $router->dispatch('/g/x', 'GET');

        self::assertSame([
            'before:Global', 'before:Group', 'before:Route',
            'after:Route', 'after:Group', 'after:Global',
        ], $log);
    }

    // =========================================================================
    // Constructor args (container / logger) forwarded to RouteDispatchMiddleware
    // =========================================================================

    public function testDispatchWithNullContainerAndLoggerStillWorks(): void
    {
        $router = new Router(container: null, logger: null);
        $router->get('/hi', fn() => 'hello');

        $response = $router->dispatch('/hi', 'GET');

        self::assertSame(200, $response->getStatusCode());
    }
}
