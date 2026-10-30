<?php
declare(strict_types=1);

namespace dzentota\Router\Tests;

use dzentota\Router\Middleware\Cache\ArrayCache;
use dzentota\Router\Middleware\CsrfMiddleware;
use dzentota\Router\Middleware\Security\SignedDoubleSubmitCookieStrategy;
use dzentota\Router\Middleware\Security\SynchronizerTokenStrategy;
use dzentota\Router\Middleware\Security\TokenGenerator;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CsrfSsrTokenTest extends TestCase
{
    /** Handler capturing the csrf_token attribute it sees. */
    private function capture(?string &$token, string $attr = 'csrf_token'): RequestHandlerInterface
    {
        return new class($token, $attr) implements RequestHandlerInterface {
            public function __construct(private ?string &$token, private string $attr) {}
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                $this->token = $r->getAttribute($this->attr);
                return (new Psr17Factory())->createResponse(200);
            }
        };
    }

    private function cookieFrom(ResponseInterface $r, string $name): string
    {
        foreach ($r->getHeader('Set-Cookie') as $c) {
            if (str_starts_with($c, $name . '=')) {
                return urldecode(explode(';', substr($c, strlen($name) + 1))[0]);
            }
        }
        $this->fail('cookie not set');
    }

    public function testDoubleSubmitFirstGetExposesFormTokenThatValidates(): void
    {
        $mw = new CsrfMiddleware(new SignedDoubleSubmitCookieStrategy(new TokenGenerator(), 'secret', 'csrf'));
        $token = null;
        $resp = $mw->process(new ServerRequest('GET', '/form'), $this->capture($token));
        $this->assertNotEmpty($token);
        $cookie = $this->cookieFrom($resp, 'csrf');
        $this->assertCount(1, $resp->getHeader('Set-Cookie'));

        $post = (new ServerRequest('POST', '/form'))
            ->withCookieParams(['csrf' => $cookie])
            ->withParsedBody(['_token' => $token]);
        $t2 = null;
        $this->assertSame(200, $mw->process($post, $this->capture($t2))->getStatusCode());
        $this->assertSame($token, $t2, 'same cookie => same form token');
    }

    public function testDoubleSubmitExistingCookieDoesNotResetCookie(): void
    {
        $mw = new CsrfMiddleware(new SignedDoubleSubmitCookieStrategy(new TokenGenerator(), 'secret', 'csrf'));
        $token = null;
        $resp = $mw->process((new ServerRequest('GET', '/'))->withCookieParams(['csrf' => 'abc']), $this->capture($token));
        $this->assertSame(hash_hmac('sha256', 'abc', 'secret'), $token);
        $this->assertSame([], $resp->getHeader('Set-Cookie'));
    }

    public function testSynchronizerWithResolverExposesTokenThatValidates(): void
    {
        $strategy = new SynchronizerTokenStrategy(
            new TokenGenerator(), new ArrayCache(),
            sessionIdResolver: fn(ServerRequestInterface $r): ?string => $r->getCookieParams()['sid'] ?? null,
        );
        $mw = new CsrfMiddleware($strategy, tokenAttribute: 'tok');
        $token = null;
        $get = (new ServerRequest('GET', '/form'))->withCookieParams(['sid' => 'S1']);
        $resp = $mw->process($get, $this->capture($token, 'tok'));
        $this->assertNotEmpty($token);
        $this->assertSame($token, $resp->getHeaderLine('X-CSRF-TOKEN'), 'single token per request');

        $post = (new ServerRequest('POST', '/form'))->withCookieParams(['sid' => 'S1'])->withParsedBody(['_token' => $token]);
        $x = null;
        $this->assertSame(200, $mw->process($post, $this->capture($x, 'tok'))->getStatusCode());

        // one-time use
        $post2 = (new ServerRequest('POST', '/form'))->withCookieParams(['sid' => 'S1'])->withParsedBody(['_token' => $token]);
        $this->assertSame(403, $mw->process($post2, $this->capture($x, 'tok'))->getStatusCode());
    }

    public function testSynchronizerGenerateTokenUsesResolver(): void
    {
        $cache = new ArrayCache();
        $strategy = new SynchronizerTokenStrategy(new TokenGenerator(), $cache, sessionIdResolver: fn() => 'S9');
        $tok = $strategy->generateToken(new ServerRequest('GET', '/'));
        $strategy->validateRequest((new ServerRequest('POST', '/'))->withHeader('X-CSRF-TOKEN', $tok));
        $this->addToAssertionCount(1);
    }
}
