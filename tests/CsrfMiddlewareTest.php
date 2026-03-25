<?php
declare(strict_types=1);

namespace dzentota\Router\Tests;

use PHPUnit\Framework\TestCase;
use dzentota\Router\Middleware\CsrfMiddleware;
use dzentota\Router\Middleware\Security\SignedDoubleSubmitCookieStrategy;
use dzentota\Router\Middleware\Security\TokenGenerator;
use dzentota\Router\Middleware\Cache\ArrayCache;
use dzentota\Router\Middleware\Security\SynchronizerTokenStrategy;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface;

class CsrfMiddlewareTest extends TestCase
{
    private Psr17Factory $factory;
    private TokenGenerator $tokenGenerator;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->tokenGenerator = new TokenGenerator();
    }

    public function testSafeMethodsPassThrough(): void
    {
        // Test all safe methods
        $safeMethods = ['GET', 'HEAD', 'OPTIONS'];
        $strategy = new SignedDoubleSubmitCookieStrategy($this->tokenGenerator, 'secret-key');
        $middleware = new CsrfMiddleware($strategy);
        $handler = $this->createMockHandler();

        foreach ($safeMethods as $method) {
            $request = new ServerRequest($method, '/test');
            $response = $middleware->process($request, $handler);
            $this->assertEquals(200, $response->getStatusCode(), "Safe method $method should pass through");
        }
    }

    public function testUnsafeMethodsWithoutTokenFail(): void
    {
        // Test all unsafe methods
        $unsafeMethods = ['POST', 'PUT', 'DELETE', 'PATCH'];
        $strategy = new SignedDoubleSubmitCookieStrategy($this->tokenGenerator, 'secret-key');
        $middleware = new CsrfMiddleware($strategy);
        $handler = $this->createMockHandler();

        foreach ($unsafeMethods as $method) {
            $request = new ServerRequest($method, '/test');
            $response = $middleware->process($request, $handler);
            $this->assertEquals(403, $response->getStatusCode(), "Unsafe method $method without token should fail");
        }
    }

    public function testSignedDoubleSubmitCookieStrategy(): void
    {
        $secret = 'secret-key';
        $strategy = new SignedDoubleSubmitCookieStrategy($this->tokenGenerator, $secret);
        $middleware = new CsrfMiddleware($strategy);
        $handler = $this->createMockHandler();

        // Step 1: GET request to receive a token via cookie
        $request = new ServerRequest('GET', '/form');
        $response = $middleware->process($request, $handler);

        // Extract CSRF cookie from response
        $cookieHeader = $response->getHeaderLine('Set-Cookie');
        $this->assertNotEmpty($cookieHeader, "Cookie header should be set");

        // Извлекаем токен из cookie
        preg_match('/__Host-csrf-token=([^;]+)/', $cookieHeader, $matches);
        $cookieToken = $matches[1] ?? '';
        $this->assertNotEmpty($cookieToken, "CSRF token cookie value should not be empty");

        // Создаем подпись для токена, как это делает класс
        $tokenSignature = hash_hmac('sha256', $cookieToken, $secret);

        // Step 2: POST with valid token
        $postRequest = new ServerRequest('POST', '/form');
        $postRequest = $postRequest->withCookieParams(['__Host-csrf-token' => $cookieToken])
            ->withHeader('X-CSRF-TOKEN', $tokenSignature); // Используем X-CSRF-TOKEN header

        $postResponse = $middleware->process($postRequest, $handler);
        $this->assertEquals(200, $postResponse->getStatusCode());

        // Step 3: POST with invalid signature
        $badRequest = new ServerRequest('POST', '/form');
        $badRequest = $badRequest->withCookieParams(['__Host-csrf-token' => $cookieToken])
            ->withHeader('X-CSRF-TOKEN', 'invalid-signature');

        $badResponse = $middleware->process($badRequest, $handler);
        $this->assertEquals(403, $badResponse->getStatusCode());
    }

    public function testSynchronizerTokenStrategy(): void
    {
        $cache = new ArrayCache();
        $strategy = new SynchronizerTokenStrategy($this->tokenGenerator, $cache);
        $middleware = new CsrfMiddleware($strategy);
        $handler = $this->createMockHandler();

        // Step 1: Create session and get token
        $sessionId = 'test-session-' . uniqid();
        $request = new ServerRequest('GET', '/form');
        $request = $request->withAttribute('session_id', $sessionId);

        $response = $middleware->process($request, $handler);

        // Token should be in header
        $token = $response->getHeaderLine('X-CSRF-TOKEN');
        $this->assertNotEmpty($token);

        // Step 2: POST with valid token and session
        $postRequest = new ServerRequest('POST', '/form');
        $postRequest = $postRequest->withAttribute('session_id', $sessionId)
            ->withHeader('X-CSRF-TOKEN', $token);

        $postResponse = $middleware->process($postRequest, $handler);
        $this->assertEquals(200, $postResponse->getStatusCode());

        // Step 3: POST with same token again (should fail, one-time use)
        $repeatRequest = new ServerRequest('POST', '/form');
        $repeatRequest = $repeatRequest->withAttribute('session_id', $sessionId)
            ->withHeader('X-CSRF-TOKEN', $token);

        $repeatResponse = $middleware->process($repeatRequest, $handler);
        $this->assertEquals(403, $repeatResponse->getStatusCode());
    }

    public function testCustomErrorHandler(): void
    {
        $strategy = new SignedDoubleSubmitCookieStrategy($this->tokenGenerator, 'secret-key');

        // Создаем логгер, который будет записывать события
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())
               ->method('warning')
               ->with('CSRF protection violation detected', $this->anything());

        $middleware = new CsrfMiddleware($strategy, $logger);
        $handler = $this->createMockHandler();

        // POST без токена должен вызвать ошибку и логирование
        $request = new ServerRequest('POST', '/form');
        $response = $middleware->process($request, $handler);

        $this->assertEquals(403, $response->getStatusCode());
    }

    /**
     * Test exempt routes functionality - routes that are excluded from CSRF protection
     */
    public function testTokenExemptRoutes(): void
    {
        $strategy = new SignedDoubleSubmitCookieStrategy($this->tokenGenerator, 'secret-key');

        // Create middleware with exempt routes
        $middleware = new CsrfMiddleware($strategy, null, [
            '/api/webhook',
            '/api/external/*'
        ]);

        $handler = $this->createMockHandler();

        // POST to exempt exact path should pass without token
        $request1 = new ServerRequest('POST', '/api/webhook');
        $response1 = $middleware->process($request1, $handler);
        $this->assertEquals(200, $response1->getStatusCode());

        // POST to exempt wildcard path should pass without token
        $request2 = new ServerRequest('POST', '/api/external/callback');
        $response2 = $middleware->process($request2, $handler);
        $this->assertEquals(200, $response2->getStatusCode());

        // POST to non-exempt path should fail without token
        $request3 = new ServerRequest('POST', '/form');
        $response3 = $middleware->process($request3, $handler);
        $this->assertEquals(403, $response3->getStatusCode());

        // Test adding exempt route dynamically
        $middleware->addExemptRoute('/api/dynamic');
        $request4 = new ServerRequest('POST', '/api/dynamic');
        $response4 = $middleware->process($request4, $handler);
        $this->assertEquals(200, $response4->getStatusCode());

        // Verify the exempt routes list
        $exemptRoutes = $middleware->getExemptRoutes();
        $this->assertCount(3, $exemptRoutes);
        $this->assertContains('/api/webhook', $exemptRoutes);
        $this->assertContains('/api/external/*', $exemptRoutes);
        $this->assertContains('/api/dynamic', $exemptRoutes);
    }

    // -------------------------------------------------------------------------
    // Rate limiting
    // -------------------------------------------------------------------------

    public function testRateLimitingBlocksAfterThreshold(): void
    {
        $cache    = new ArrayCache();
        $strategy = new SignedDoubleSubmitCookieStrategy(new TokenGenerator(), 'secret-key');

        $mw = new CsrfMiddleware(
            strategy:             $strategy,
            cache:                $cache,
            maxFailedAttempts:    3,
            failureWindowSeconds: 3600,
        );

        $handler = $this->createMockHandler();

        // Submit 3 POST requests without a CSRF token — each should record a failure.
        for ($i = 0; $i < 3; $i++) {
            $request  = new ServerRequest('POST', '/form', [], null, '1.1', ['REMOTE_ADDR' => '10.0.0.1']);
            $response = $mw->process($request, $handler);
            $this->assertSame(403, $response->getStatusCode(), "Request {$i} should be rejected (no token)");
        }

        // The 4th request should be blocked by the rate limiter (not even checked for token).
        $request  = new ServerRequest('POST', '/form', [], null, '1.1', ['REMOTE_ADDR' => '10.0.0.1']);
        $response = $mw->process($request, $handler);
        $this->assertSame(403, $response->getStatusCode(), '4th request should be rate-limited');

        // Decode response body to confirm it's the rate-limit message, not just a token error.
        $body = json_decode((string)$response->getBody(), true);
        $this->assertSame('Too many failed requests', $body['message']);
    }

    public function testRateLimitingDoesNotAffectDifferentIps(): void
    {
        $cache    = new ArrayCache();
        $strategy = new SignedDoubleSubmitCookieStrategy(new TokenGenerator(), 'secret-key');

        $mw = new CsrfMiddleware(
            strategy:             $strategy,
            cache:                $cache,
            maxFailedAttempts:    2,
            failureWindowSeconds: 3600,
        );

        $handler = $this->createMockHandler();

        // Exhaust failures for IP A.
        for ($i = 0; $i < 2; $i++) {
            $r = new ServerRequest('POST', '/form', [], null, '1.1', ['REMOTE_ADDR' => '192.168.1.1']);
            $mw->process($r, $handler);
        }

        // IP B should not be affected.
        $requestB = new ServerRequest('POST', '/form', [], null, '1.1', ['REMOTE_ADDR' => '192.168.1.2']);
        $responseB = $mw->process($requestB, $handler);

        // B has no token either, so it should get a 403 — but the body should say token failure, not rate limit.
        $body = json_decode((string)$responseB->getBody(), true);
        $this->assertSame(403, $responseB->getStatusCode());
        $this->assertNotSame('Too many failed requests', $body['message'] ?? '');
    }

    public function testRateLimitingDisabledWhenNoCacheProvided(): void
    {
        // Without a cache, unlimited failures are allowed (rate limiting is opt-in).
        $strategy = new SignedDoubleSubmitCookieStrategy(new TokenGenerator(), 'secret-key');
        $mw       = new CsrfMiddleware(strategy: $strategy);
        $handler  = $this->createMockHandler();

        for ($i = 0; $i < 10; $i++) {
            $request  = new ServerRequest('POST', '/form', [], null, '1.1', ['REMOTE_ADDR' => '1.2.3.4']);
            $response = $mw->process($request, $handler);
            // All should be rejected for missing token — none should carry 'Too many failed requests'.
            $body = json_decode((string)$response->getBody(), true);
            $this->assertNotSame('Too many failed requests', $body['message'] ?? '', "Iteration {$i}");
        }
    }

    private function createMockHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Psr17Factory())->createResponse(200);
            }
        };
    }
}
