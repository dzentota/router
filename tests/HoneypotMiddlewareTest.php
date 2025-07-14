<?php
declare(strict_types=1);

namespace dzentota\Router\Test;

use PHPUnit\Framework\TestCase;
use dzentota\Router\Middleware\HoneypotMiddleware;
use dzentota\Router\Middleware\Contract\RateLimitStorageInterface;
use dzentota\Router\Middleware\Cache\InMemoryRateLimitStorage;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;

class HoneypotMiddlewareTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    public function testBlocksFilledHoneypotFields(): void
    {
        $middleware = new HoneypotMiddleware(['website', 'url']);

        // Create a mock handler that returns a 200 response
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Psr17Factory())->createResponse(200);
            }
        };

        // POST request with filled honeypot field
        $request = new ServerRequest('POST', '/test', [], null, '1.1', [
            'REMOTE_ADDR' => '192.168.1.1'
        ]);
        $request = $request->withParsedBody([
            'name' => 'Test User',
            'website' => 'http://spam.com' // Honeypot field filled
        ]);

        // Should be blocked
        $response = $middleware->process($request, $handler);
        $this->assertEquals(429, $response->getStatusCode());
    }

    public function testPassesWithEmptyHoneypotFields(): void
    {
        $middleware = new HoneypotMiddleware(['website', 'url']);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Psr17Factory())->createResponse(200);
            }
        };

        // POST request with empty honeypot field
        $request = new ServerRequest('POST', '/test', [], null, '1.1', [
            'REMOTE_ADDR' => '192.168.1.1'
        ]);
        $request = $request->withParsedBody([
            'name' => 'Test User',
            'website' => '' // Honeypot field empty
        ]);

        // Should pass
        $response = $middleware->process($request, $handler);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testBlocksRapidFormSubmission(): void
    {
        $middleware = new HoneypotMiddleware(['website'], 3); // 3 seconds minimum

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Psr17Factory())->createResponse(200);
            }
        };

        // POST request with timestamp too recent
        $request = new ServerRequest('POST', '/test', [], null, '1.1', [
            'REMOTE_ADDR' => '192.168.1.1'
        ]);
        $request = $request->withParsedBody([
            'name' => 'Test User',
            '_timestamp' => (string)(time() - 1) // Just 1 second ago
        ]);

        // Should be blocked (too rapid)
        $response = $middleware->process($request, $handler);
        $this->assertEquals(429, $response->getStatusCode());
    }

    public function testPassesSlowFormSubmission(): void
    {
        $middleware = new HoneypotMiddleware(['website'], 3); // 3 seconds minimum

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Psr17Factory())->createResponse(200);
            }
        };

        // POST request with timestamp old enough
        $request = new ServerRequest('POST', '/test', [], null, '1.1', [
            'REMOTE_ADDR' => '192.168.1.1'
        ]);
        $request = $request->withParsedBody([
            'name' => 'Test User',
            '_timestamp' => (string)(time() - 5) // 5 seconds ago
        ]);

        // Should pass (slow enough)
        $response = $middleware->process($request, $handler);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testBlocksRapidSubmissionsFromSameIP(): void
    {
        // Create a custom storage for testing
        $storage = new class implements RateLimitStorageInterface {
            private array $submissions = [];

            public function recordSubmission(string $key, int $timestamp): void
            {
                $this->submissions[$key . '_' . $timestamp] = $timestamp;
            }

            public function getRecentSubmissions(int $timeframe = 60): array
            {
                return $this->submissions;
            }

            public function getSubmissionsForKey(string $key, int $timeframe = 60): array
            {
                // For testing, simulate 5 previous submissions for IP 192.168.1.1
                if ($key === '192.168.1.1') {
                    $result = [];
                    $now = time();
                    for ($i = 1; $i <= 5; $i++) {
                        $result[$key . '_' . ($now - $i)] = $now - $i;
                    }
                    return $result;
                }
                return [];
            }

            public function clearAll(): void
            {
                $this->submissions = [];
            }
        };

        $middleware = new HoneypotMiddleware(['website'], 1, true, null, $storage, 5);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Psr17Factory())->createResponse(200);
            }
        };

        // POST request from IP that already has 5 submissions
        $request = new ServerRequest('POST', '/test', [], null, '1.1', [
            'REMOTE_ADDR' => '192.168.1.1'
        ]);
        $request = $request->withParsedBody([
            'name' => 'Test User'
        ]);

        // Should be blocked (too many submissions)
        $response = $middleware->process($request, $handler);
        $this->assertEquals(429, $response->getStatusCode());

        // Different IP should pass
        $request2 = new ServerRequest('POST', '/test', [], null, '1.1', [
            'REMOTE_ADDR' => '192.168.1.2'
        ]);
        $request2 = $request2->withParsedBody([
            'name' => 'Different User'
        ]);

        $response2 = $middleware->process($request2, $handler);
        $this->assertEquals(200, $response2->getStatusCode());
    }

    public function testAllowsMultipleSubmissionsAfterWait(): void
    {
        // Create a testable storage implementation
        $storage = new class implements RateLimitStorageInterface {
            private array $submissions = [];
            private bool $resetCalled = false;

            public function recordSubmission(string $key, int $timestamp): void
            {
                $this->submissions[$key . '_' . $timestamp] = $timestamp;
            }

            public function getRecentSubmissions(int $timeframe = 60): array
            {
                if ($this->resetCalled) {
                    return [];
                }
                return $this->submissions;
            }

            public function getSubmissionsForKey(string $key, int $timeframe = 60): array
            {
                if ($this->resetCalled) {
                    return [];
                }
                return array_filter(
                    $this->submissions,
                    fn($time, $submissionKey) => strpos($submissionKey, $key . '_') === 0,
                    ARRAY_FILTER_USE_BOTH
                );
            }

            public function clearAll(): void
            {
                $this->resetCalled = true;
            }
        };

        $middleware = new HoneypotMiddleware(['website'], 1, true, null, $storage, 1);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Psr17Factory())->createResponse(200);
            }
        };

        // First request from IP
        $request1 = new ServerRequest('POST', '/test', [], null, '1.1', [
            'REMOTE_ADDR' => '192.168.1.5'
        ]);
        $request1 = $request1->withParsedBody([
            'name' => 'Test User'
        ]);

        // First request should pass
        $response1 = $middleware->process($request1, $handler);
        $this->assertEquals(200, $response1->getStatusCode());

        // Simulate clearing the storage (as if time has passed)
        $middleware->resetStorage();

        // Second request from same IP after waiting period
        $request2 = new ServerRequest('POST', '/test', [], null, '1.1', [
            'REMOTE_ADDR' => '192.168.1.5'
        ]);
        $request2 = $request2->withParsedBody([
            'name' => 'Test User Again'
        ]);

        // Should pass after resetting storage
        $response2 = $middleware->process($request2, $handler);
        $this->assertEquals(200, $response2->getStatusCode());
    }
}
