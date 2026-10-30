<?php
declare(strict_types=1);

namespace dzentota\Router\Test;

use dzentota\Router\Middleware\Builder\HoneypotMiddlewareBuilder;
use dzentota\Router\Middleware\Cache\InMemoryRateLimitStorage;
use dzentota\Router\Middleware\HoneypotMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class HoneypotHardeningTest extends TestCase
{
    private const SECRET = 'test-secret-0123456789abcdef';

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Psr17Factory())->createResponse(200);
            }
        };
    }

    private function post(array $body, string $ip = '10.0.0.1'): ServerRequestInterface
    {
        return (new ServerRequest('POST', '/c', [], null, '1.1', ['REMOTE_ADDR' => $ip]))->withParsedBody($body);
    }

    private function signed(int $ts, string $secret = self::SECRET): string
    {
        $mac = rtrim(strtr(base64_encode(hash_hmac('sha256', (string)$ts, $secret, true)), '+/', '-_'), '=');
        return $ts . '.' . $mac;
    }

    private function mw(int $min = 2, int $maxAge = 3600): HoneypotMiddleware
    {
        return new HoneypotMiddleware(['website'], $min, true, null, null, 100, self::SECRET, $maxAge);
    }

    public function testGenerateTimestampIsSignedWhenSecretSet(): void
    {
        $token = $this->mw()->generateTimestamp();
        $this->assertMatchesRegularExpression('/^\d+\.[A-Za-z0-9_-]+$/', $token);
        [$ts] = explode('.', $token);
        $this->assertSame($this->signed((int)$ts), $token);
    }

    public function testValidSignedTokenPasses(): void
    {
        $r = $this->mw()->process($this->post(['_timestamp' => $this->signed(time() - 10)]), $this->handler());
        $this->assertSame(200, $r->getStatusCode());
    }

    public function testRoundTripOfGeneratedTokenPassesWithZeroThreshold(): void
    {
        $mw = $this->mw(0);
        $r = $mw->process($this->post(['_timestamp' => $mw->generateTimestamp()]), $this->handler());
        $this->assertSame(200, $r->getStatusCode());
    }

    /** @dataProvider badTokens */
    public function testInvalidTokensAreBlocked(mixed $value): void
    {
        $body = $value === '__absent__' ? ['x' => 'y'] : ['_timestamp' => $value];
        $r = $this->mw()->process($this->post($body), $this->handler());
        $this->assertSame(429, $r->getStatusCode());
    }

    public static function badTokens(): array
    {
        $ts = time() - 10;
        return [
            'missing' => ['__absent__'],
            'empty' => [''],
            'unsigned' => [(string)$ts],
            'array' => [['a']],
            'forged-mac' => [$ts . '.AAAA'],
            'wrong-secret' => [self::signedStatic($ts, 'other')],
            'tampered-ts' => [($ts - 100) . '.' . explode('.', self::signedStatic($ts, self::SECRET))[1]],
            'garbage' => ['abc.def.ghi'],
        ];
    }

    private static function signedStatic(int $ts, string $secret): string
    {
        return $ts . '.' . rtrim(strtr(base64_encode(hash_hmac('sha256', (string)$ts, $secret, true)), '+/', '-_'), '=');
    }

    public function testTooFastSignedTokenBlocked(): void
    {
        $r = $this->mw(5)->process($this->post(['_timestamp' => $this->signed(time() - 1)]), $this->handler());
        $this->assertSame(429, $r->getStatusCode());
    }

    public function testMaxAgeConfigurable(): void
    {
        $r = $this->mw(2, 60)->process($this->post(['_timestamp' => $this->signed(time() - 120)]), $this->handler());
        $this->assertSame(429, $r->getStatusCode());
        $r = $this->mw(2, 600)->process($this->post(['_timestamp' => $this->signed(time() - 120)]), $this->handler());
        $this->assertSame(200, $r->getStatusCode());
    }

    public function testDefaultMaxAgeIs3600WithoutSecret(): void
    {
        $mw = new HoneypotMiddleware(['website']);
        $this->assertSame(429, $mw->process($this->post(['_timestamp' => (string)(time() - 3700)]), $this->handler())->getStatusCode());
        $mw = new HoneypotMiddleware(['website'], 2, true, null, null, 5, null, 7200);
        $this->assertSame(200, $mw->process($this->post(['_timestamp' => (string)(time() - 3700)]), $this->handler())->getStatusCode());
    }

    public function testWithoutSecretBehaviourUnchanged(): void
    {
        $mw = new HoneypotMiddleware(['website']);
        $this->assertMatchesRegularExpression('/^\d+$/', $mw->generateTimestamp());
        $this->assertSame(200, $mw->process($this->post(['x' => 'y']), $this->handler())->getStatusCode());
    }

    public function testArrayHoneypotValueIsViolationNotTypeError(): void
    {
        $r = (new HoneypotMiddleware(['website']))->process($this->post(['website' => ['spam']]), $this->handler());
        $this->assertSame(429, $r->getStatusCode());
    }

    public function testEmptyArrayHoneypotValueIsViolation(): void
    {
        $r = (new HoneypotMiddleware(['website']))->process($this->post(['website' => []]), $this->handler());
        $this->assertSame(429, $r->getStatusCode());
    }

    public function testBlockedResponseDoesNotEchoRequestData(): void
    {
        $r = (new HoneypotMiddleware(['website']))->process(
            $this->post(['website' => 'EVIL<script>'], '203.0.113.77'),
            $this->handler()
        );
        $body = (string)$r->getBody();
        $this->assertStringNotContainsString('203.0.113.77', $body);
        $this->assertStringNotContainsString('EVIL', $body);
        $this->assertStringNotContainsString('website', $body);
    }

    public function testClientIpResolverUsedForRateLimitKey(): void
    {
        $storage = new InMemoryRateLimitStorage();
        $mw = new HoneypotMiddleware(['website'], 2, true, null, $storage, 1, null, 3600,
            fn(ServerRequestInterface $r): ?string => $r->getHeaderLine('X-Real-IP') ?: null);
        $h = $this->handler();
        $this->assertSame(200, $mw->process($this->post([], '10.0.0.1')->withHeader('X-Real-IP', '1.1.1.1'), $h)->getStatusCode());
        // Same REMOTE_ADDR, different resolved IP -> allowed
        $this->assertSame(200, $mw->process($this->post([], '10.0.0.1')->withHeader('X-Real-IP', '2.2.2.2'), $h)->getStatusCode());
        // Same resolved IP -> blocked
        $this->assertSame(429, $mw->process($this->post([], '10.0.0.9')->withHeader('X-Real-IP', '1.1.1.1'), $h)->getStatusCode());
        $this->assertCount(1, $storage->getSubmissionsForKey('1.1.1.1', 60));
    }

    public function testResolverReturningNullSkipsRateLimit(): void
    {
        $mw = new HoneypotMiddleware(['website'], 2, true, null, null, 1, null, 3600, fn() => null);
        $h = $this->handler();
        $this->assertSame(200, $mw->process($this->post([]), $h)->getStatusCode());
        $this->assertSame(200, $mw->process($this->post([]), $h)->getStatusCode());
    }

    public function testBuilderSupportsNewOptions(): void
    {
        $mw = HoneypotMiddlewareBuilder::create()
            ->withHoneypotFields(['website'])
            ->withTimestampSecret(self::SECRET)
            ->withMaxTimestampAge(60)
            ->withClientIpResolver(fn() => null)
            ->build();
        $token = $mw->generateTimestamp();
        [$ts] = explode('.', $token);
        $this->assertSame($this->signed((int)$ts), $token);
        $this->assertSame(429, $mw->process($this->post(['_timestamp' => $this->signed(time() - 120)]), $this->handler())->getStatusCode());
        $this->assertSame(200, $mw->process($this->post(['_timestamp' => $this->signed(time() - 10)]), $this->handler())->getStatusCode());
    }
}
