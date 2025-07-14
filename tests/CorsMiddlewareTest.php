<?php
declare(strict_types=1);

namespace dzentota\Router\Tests;

use PHPUnit\Framework\TestCase;
use dzentota\Router\Middleware\CorsMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CorsMiddlewareTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    /**
     * Test preflight request with allowed origin
     */
    public function testPreflightRequestWithAllowedOrigin(): void
    {
        $middleware = new CorsMiddleware([
            'allowed_origins' => ['https://example.com'],
            'allowed_methods' => ['GET', 'POST', 'PUT'],
            'allowed_headers' => ['Content-Type', 'X-Requested-With'],
            'max_age' => 3600
        ]);

        $request = new ServerRequest('OPTIONS', '/api/resource');
        $request = $request->withHeader('Origin', 'https://example.com')
                          ->withHeader('Access-Control-Request-Method', 'POST')
                          ->withHeader('Access-Control-Request-Headers', 'Content-Type');

        $handler = $this->createMockHandler();
        $response = $middleware->process($request, $handler);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertEquals('GET, POST, PUT', $response->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertEquals('Content-Type, X-Requested-With', $response->getHeaderLine('Access-Control-Allow-Headers'));
        $this->assertEquals('3600', $response->getHeaderLine('Access-Control-Max-Age'));
    }

    /**
     * Test preflight request with disallowed origin
     */
    public function testPreflightRequestWithDisallowedOrigin(): void
    {
        $middleware = new CorsMiddleware([
            'allowed_origins' => ['https://example.com'],
        ]);

        $request = new ServerRequest('OPTIONS', '/api/resource');
        $request = $request->withHeader('Origin', 'https://malicious.com')
                          ->withHeader('Access-Control-Request-Method', 'POST');

        $handler = $this->createMockHandler();
        $response = $middleware->process($request, $handler);

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    /**
     * Test preflight request with disallowed method
     */
    public function testPreflightRequestWithDisallowedMethod(): void
    {
        $middleware = new CorsMiddleware([
            'allowed_origins' => ['https://example.com'],
            'allowed_methods' => ['GET', 'POST'],
        ]);

        $request = new ServerRequest('OPTIONS', '/api/resource');
        $request = $request->withHeader('Origin', 'https://example.com')
                          ->withHeader('Access-Control-Request-Method', 'DELETE');

        $handler = $this->createMockHandler();
        $response = $middleware->process($request, $handler);

        $this->assertEquals(403, $response->getStatusCode());
    }

    /**
     * Test preflight request with disallowed headers
     */
    public function testPreflightRequestWithDisallowedHeaders(): void
    {
        $middleware = new CorsMiddleware([
            'allowed_origins' => ['https://example.com'],
            'allowed_methods' => ['GET', 'POST'],
            'allowed_headers' => ['Content-Type', 'Authorization'],
        ]);

        $request = new ServerRequest('OPTIONS', '/api/resource');
        $request = $request->withHeader('Origin', 'https://example.com')
                          ->withHeader('Access-Control-Request-Method', 'POST')
                          ->withHeader('Access-Control-Request-Headers', 'Content-Type, X-Custom-Header');

        $handler = $this->createMockHandler();
        $response = $middleware->process($request, $handler);

        $this->assertEquals(403, $response->getStatusCode());
    }

    /**
     * Test actual request with allowed origin
     */
    public function testActualRequestWithAllowedOrigin(): void
    {
        $middleware = new CorsMiddleware([
            'allowed_origins' => ['https://example.com'],
            'exposed_headers' => ['X-Total-Count'],
        ]);

        $request = new ServerRequest('GET', '/api/resource');
        $request = $request->withHeader('Origin', 'https://example.com');

        $handler = $this->createMockHandlerWithResponse(
            $this->factory->createResponse(200)
                ->withHeader('X-Total-Count', '42')
        );

        $response = $middleware->process($request, $handler);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertEquals('X-Total-Count', $response->getHeaderLine('Access-Control-Expose-Headers'));
    }

    /**
     * Test actual request with disallowed origin
     */
    public function testActualRequestWithDisallowedOrigin(): void
    {
        $middleware = new CorsMiddleware([
            'allowed_origins' => ['https://example.com'],
        ]);

        $request = new ServerRequest('GET', '/api/resource');
        $request = $request->withHeader('Origin', 'https://malicious.com');

        $handler = $this->createMockHandler();
        $response = $middleware->process($request, $handler);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    /**
     * Test wildcard origin
     */
    public function testWildcardOriginWithRequireExactOriginDisabled(): void
    {
        $middleware = new CorsMiddleware([
            'allowed_origins' => ['*'],
            'require_exact_origin' => false,
        ]);

        $request = new ServerRequest('OPTIONS', '/api/resource');
        $request = $request->withHeader('Origin', 'https://any-domain.com')
                          ->withHeader('Access-Control-Request-Method', 'GET');

        $handler = $this->createMockHandler();
        $response = $middleware->process($request, $handler);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('https://any-domain.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    /**
     * Test wildcard origin with require_exact_origin still enabled
     */
    public function testWildcardOriginWithRequireExactOriginEnabled(): void
    {
        $middleware = new CorsMiddleware([
            'allowed_origins' => ['*'],
            'require_exact_origin' => true,  // Default
        ]);

        $request = new ServerRequest('OPTIONS', '/api/resource');
        $request = $request->withHeader('Origin', 'https://any-domain.com')
                          ->withHeader('Access-Control-Request-Method', 'GET');

        $handler = $this->createMockHandler();
        $response = $middleware->process($request, $handler);

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    /**
     * Test allow_credentials configuration
     */
    public function testAllowCredentialsEnabled(): void
    {
        $middleware = new CorsMiddleware([
            'allowed_origins' => ['https://example.com'],
            'allow_credentials' => true,
        ]);

        // Test preflight request
        $request = new ServerRequest('OPTIONS', '/api/resource');
        $request = $request->withHeader('Origin', 'https://example.com')
                          ->withHeader('Access-Control-Request-Method', 'GET');

        $handler = $this->createMockHandler();
        $response = $middleware->process($request, $handler);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));

        // Test actual request
        $request = new ServerRequest('GET', '/api/resource');
        $request = $request->withHeader('Origin', 'https://example.com');

        $response = $middleware->process($request, $handler);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    /**
     * Test allow_credentials disabled (default)
     */
    public function testAllowCredentialsDisabled(): void
    {
        $middleware = new CorsMiddleware([
            'allowed_origins' => ['https://example.com'],
            // allow_credentials is false by default
        ]);

        // Test preflight request
        $request = new ServerRequest('OPTIONS', '/api/resource');
        $request = $request->withHeader('Origin', 'https://example.com')
                          ->withHeader('Access-Control-Request-Method', 'GET');

        $handler = $this->createMockHandler();
        $response = $middleware->process($request, $handler);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Access-Control-Allow-Credentials'));

        // Test actual request
        $request = new ServerRequest('GET', '/api/resource');
        $request = $request->withHeader('Origin', 'https://example.com');

        $response = $middleware->process($request, $handler);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Access-Control-Allow-Credentials'));
    }

    /**
     * Test request without origin header
     */
    public function testRequestWithoutOriginHeader(): void
    {
        $middleware = new CorsMiddleware([
            'allowed_origins' => ['https://example.com'],
        ]);

        $request = new ServerRequest('GET', '/api/resource');
        // No Origin header

        $handler = $this->createMockHandler();
        $response = $middleware->process($request, $handler);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    /**
     * Test default configuration
     */
    public function testDefaultConfiguration(): void
    {
        $middleware = new CorsMiddleware();

        $request = new ServerRequest('OPTIONS', '/api/resource');
        $request = $request->withHeader('Origin', 'https://example.com')
                          ->withHeader('Access-Control-Request-Method', 'GET');

        $handler = $this->createMockHandler();
        $response = $middleware->process($request, $handler);

        // Default config allows no origins
        $this->assertEquals(403, $response->getStatusCode());
    }

    /**
     * Create a mock request handler that returns a 200 response
     */
    private function createMockHandler(): RequestHandlerInterface
    {
        return new class($this->factory->createResponse(200)) implements RequestHandlerInterface {
            private $response;

            public function __construct(ResponseInterface $response)
            {
                $this->response = $response;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    /**
     * Create a mock request handler that returns a custom response
     */
    private function createMockHandlerWithResponse(ResponseInterface $response): RequestHandlerInterface
    {
        return new class($response) implements RequestHandlerInterface {
            private $response;

            public function __construct(ResponseInterface $response)
            {
                $this->response = $response;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }
}
