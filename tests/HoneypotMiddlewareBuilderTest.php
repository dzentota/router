<?php
declare(strict_types=1);

namespace dzentota\Router\Tests;

use dzentota\Router\Middleware\Builder\HoneypotMiddlewareBuilder;
use dzentota\Router\Middleware\Cache\InMemoryRateLimitStorage;
use dzentota\Router\Middleware\Contract\RateLimitStorageInterface;
use dzentota\Router\Middleware\HoneypotMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HoneypotMiddlewareBuilderTest extends TestCase
{
    public function testCreateReturnsBuilder(): void
    {
        $builder = HoneypotMiddlewareBuilder::create();

        $this->assertInstanceOf(HoneypotMiddlewareBuilder::class, $builder);
    }

    public function testBuildCreatesHoneypotMiddleware(): void
    {
        $middleware = HoneypotMiddlewareBuilder::create()->build();

        $this->assertInstanceOf(HoneypotMiddleware::class, $middleware);
    }

    public function testWithHoneypotFieldsModifiesFields(): void
    {
        $fields = ['test_field1', 'test_field2'];

        $builder = HoneypotMiddlewareBuilder::create();
        $newBuilder = $builder->withHoneypotFields($fields);

        // Проверяем, что возвращается новый экземпляр
        $this->assertNotSame($builder, $newBuilder);

        // Создаем middleware и проверяем через reflection, что поля установлены
        $middleware = $newBuilder->build();
        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('honeypotFields');

        $this->assertEquals($fields, $reflectionProperty->getValue($middleware));
    }

    public function testWithMinTimeThresholdModifiesThreshold(): void
    {
        $threshold = 10;

        $builder = HoneypotMiddlewareBuilder::create();
        $newBuilder = $builder->withMinTimeThreshold($threshold);

        // Проверяем, что возвращается новый экземпляр
        $this->assertNotSame($builder, $newBuilder);

        // Создаем middleware и проверяем через reflection, что порог установлен
        $middleware = $newBuilder->build();
        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('minTimeThreshold');

        $this->assertEquals($threshold, $reflectionProperty->getValue($middleware));
    }

    public function testWithBlockOnViolationModifiesBlockFlag(): void
    {
        $block = false;

        $builder = HoneypotMiddlewareBuilder::create();
        $newBuilder = $builder->withBlockOnViolation($block);

        // Проверяем, что возвращается новый экземпляр
        $this->assertNotSame($builder, $newBuilder);

        // Создаем middleware и проверяем через reflection
        $middleware = $newBuilder->build();
        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('blockOnViolation');

        $this->assertEquals($block, $reflectionProperty->getValue($middleware));
    }

    public function testWithLoggerSetsLogger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        $builder = HoneypotMiddlewareBuilder::create();
        $newBuilder = $builder->withLogger($logger);

        // Проверяем, что возвращается новый экземпляр
        $this->assertNotSame($builder, $newBuilder);

        // Создаем middleware и проверяем через reflection
        $middleware = $newBuilder->build();
        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('logger');

        $this->assertSame($logger, $reflectionProperty->getValue($middleware));
    }

    public function testWithStorageSetsStorage(): void
    {
        $storage = $this->createMock(RateLimitStorageInterface::class);

        $builder = HoneypotMiddlewareBuilder::create();
        $newBuilder = $builder->withStorage($storage);

        // Проверяем, что возвращается новый экземпляр
        $this->assertNotSame($builder, $newBuilder);

        // Создаем middleware и проверяем через reflection
        $middleware = $newBuilder->build();
        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('storage');

        $this->assertSame($storage, $reflectionProperty->getValue($middleware));
    }

    public function testWithMaxSubmissionsPerMinuteSetsMaxSubmissions(): void
    {
        $maxSubmissions = 20;

        $builder = HoneypotMiddlewareBuilder::create();
        $newBuilder = $builder->withMaxSubmissionsPerMinute($maxSubmissions);

        // Проверяем, что возвращается новый экземпляр
        $this->assertNotSame($builder, $newBuilder);

        // Создаем middleware и проверяем через reflection
        $middleware = $newBuilder->build();
        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('maxSubmissionsPerMinute');

        $this->assertEquals($maxSubmissions, $reflectionProperty->getValue($middleware));
    }

    public function testChainedMethodCalls(): void
    {
        $fields = ['field1', 'field2'];
        $threshold = 5;
        $maxSubmissions = 15;
        $storage = new InMemoryRateLimitStorage();
        $logger = $this->createMock(LoggerInterface::class);

        $middleware = HoneypotMiddlewareBuilder::create()
            ->withHoneypotFields($fields)
            ->withMinTimeThreshold($threshold)
            ->withMaxSubmissionsPerMinute($maxSubmissions)
            ->withStorage($storage)
            ->withLogger($logger)
            ->withBlockOnViolation(false)
            ->build();

        $this->assertInstanceOf(HoneypotMiddleware::class, $middleware);

        $reflectionClass = new \ReflectionClass($middleware);

        $honeypotFieldsProp = $reflectionClass->getProperty('honeypotFields');
        $this->assertEquals($fields, $honeypotFieldsProp->getValue($middleware));

        $thresholdProp = $reflectionClass->getProperty('minTimeThreshold');
        $this->assertEquals($threshold, $thresholdProp->getValue($middleware));

        $maxSubmissionsProp = $reflectionClass->getProperty('maxSubmissionsPerMinute');
        $this->assertEquals($maxSubmissions, $maxSubmissionsProp->getValue($middleware));

        $storageProp = $reflectionClass->getProperty('storage');
        $this->assertSame($storage, $storageProp->getValue($middleware));

        $loggerProp = $reflectionClass->getProperty('logger');
        $this->assertSame($logger, $loggerProp->getValue($middleware));

        $blockProp = $reflectionClass->getProperty('blockOnViolation');
        $this->assertFalse($blockProp->getValue($middleware));
    }
}
