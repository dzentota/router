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
