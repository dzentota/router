<?php
declare(strict_types=1);

namespace dzentota\Router\Tests;

use dzentota\Router\Middleware\Cache\ArrayCache;
use dzentota\Router\Middleware\CsrfMiddleware;
use dzentota\Router\Middleware\Security\SignedDoubleSubmitCookieStrategy;
use dzentota\Router\Middleware\Security\TokenGenerator;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CsrfMiddlewareOptionsTest extends TestCase
{
    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                return (new Psr17Factory())->createResponse(200);
            }
        };
    }

    private function post(string $remote, string $xff): ServerRequestInterface
    {
        return (new ServerRequest('POST', '/f', ['X-Forwarded-For' => $xff], null, '1.1', ['REMOTE_ADDR' => $remote]));
    }

    public function testDefaultRateLimitIgnoresForwardedFor(): void
    {
        $mw = new CsrfMiddleware(
            strategy: new SignedDoubleSubmitCookieStrategy(new TokenGenerator(), 's'),
            cache: new ArrayCache(),
            maxFailedAttempts: 1,
        );
        $mw->process($this->post('10.0.0.1', '1.1.1.1'), $this->handler());
        $r = $mw->process($this->post('10.0.0.1', '2.2.2.2'), $this->handler());
        $this->assertStringContainsString('Too many', (string)$r->getBody());
    }

    public function testCustomClientIpResolverSeparatesBuckets(): void
    {
        $mw = new CsrfMiddleware(
            strategy: new SignedDoubleSubmitCookieStrategy(new TokenGenerator(), 's'),
            cache: new ArrayCache(),
            maxFailedAttempts: 1,
            clientIpResolver: fn(ServerRequestInterface $r): string => $r->getHeaderLine('X-Forwarded-For'),
        );
        $mw->process($this->post('10.0.0.1', '1.1.1.1'), $this->handler());
        $r = $mw->process($this->post('10.0.0.1', '2.2.2.2'), $this->handler());
        $this->assertSame(403, $r->getStatusCode());
        $this->assertStringNotContainsString('Too many', (string)$r->getBody());
        $r = $mw->process($this->post('10.0.0.1', '1.1.1.1'), $this->handler());
        $this->assertStringContainsString('Too many', (string)$r->getBody());
    }

    public function testCustomFailureResponseFactory(): void
    {
        $seen = null;
        $mw = new CsrfMiddleware(
            strategy: new SignedDoubleSubmitCookieStrategy(new TokenGenerator(), 's'),
            failureResponseFactory: function (ServerRequestInterface $req, string $reason) use (&$seen): ResponseInterface {
                $seen = $reason;
                $r = (new Psr17Factory())->createResponse(419)->withHeader('Content-Type', 'text/html');
                $r->getBody()->write('<p>Expired</p>');
                return $r;
            },
        );
        $r = $mw->process($this->post('10.0.0.1', ''), $this->handler());
        $this->assertSame(419, $r->getStatusCode());
        $this->assertSame('text/html', $r->getHeaderLine('Content-Type'));
        $this->assertSame('CSRF token cookie missing', $seen);
    }
}
