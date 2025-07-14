<?php
declare(strict_types=1);

namespace dzentota\Router\Tests;

use dzentota\Router\Middleware\Builder\CorsMiddlewareBuilder;
use dzentota\Router\Middleware\CorsMiddleware;
use PHPUnit\Framework\TestCase;

class CorsMiddlewareBuilderTest extends TestCase
{
    public function testCreateReturnsBuilder(): void
    {
        $builder = CorsMiddlewareBuilder::create();

        $this->assertInstanceOf(CorsMiddlewareBuilder::class, $builder);
    }

    public function testBuildCreatesCorsMiddleware(): void
    {
        $middleware = CorsMiddlewareBuilder::create()->build();

        $this->assertInstanceOf(CorsMiddleware::class, $middleware);
    }

    public function testWithAllowedOriginsModifiesConfig(): void
    {
        $origins = ['https://example.com', 'https://api.example.com'];

        $builder = CorsMiddlewareBuilder::create();
        $newBuilder = $builder->withAllowedOrigins($origins);

        // Проверяем, что возвращается новый экземпляр
        $this->assertNotSame($builder, $newBuilder);

        // Создаем middleware и проверяем через reflection
        $middleware = $newBuilder->build();
        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('config');
        $reflectionProperty->setAccessible(true);
        $config = $reflectionProperty->getValue($middleware);

        $this->assertEquals($origins, $config['allowed_origins']);
    }

    public function testAddAllowedOriginModifiesConfig(): void
    {
        $origin = 'https://example.com';

        $builder = CorsMiddlewareBuilder::create();
        $newBuilder = $builder->addAllowedOrigin($origin);

        // Создаем middleware и проверяем через reflection
        $middleware = $newBuilder->build();
        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('config');
        $reflectionProperty->setAccessible(true);
        $config = $reflectionProperty->getValue($middleware);

        $this->assertContains($origin, $config['allowed_origins']);
    }

    public function testAllowAllOriginsModifiesConfig(): void
    {
        $builder = CorsMiddlewareBuilder::create();
        $newBuilder = $builder->allowAllOrigins();

        // Создаем middleware и проверяем через reflection
        $middleware = $newBuilder->build();
        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('config');
        $reflectionProperty->setAccessible(true);
        $config = $reflectionProperty->getValue($middleware);

        $this->assertEquals(['*'], $config['allowed_origins']);
    }

    public function testWithAllowedMethodsModifiesConfig(): void
    {
        $methods = ['GET', 'POST'];

        $builder = CorsMiddlewareBuilder::create();
        $newBuilder = $builder->withAllowedMethods($methods);

        // Создаем middleware и проверяем через reflection
        $middleware = $newBuilder->build();
        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('config');
        $reflectionProperty->setAccessible(true);
        $config = $reflectionProperty->getValue($middleware);

        $this->assertEquals($methods, $config['allowed_methods']);
    }

    public function testAddAllowedMethodModifiesConfig(): void
    {
        $method = 'PATCH';

        $builder = CorsMiddlewareBuilder::create();
        $newBuilder = $builder->addAllowedMethod($method);

        // Создаем middleware и проверяем через reflection
        $middleware = $newBuilder->build();
        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('config');
        $reflectionProperty->setAccessible(true);
        $config = $reflectionProperty->getValue($middleware);

        $this->assertContains($method, $config['allowed_methods']);
    }

    public function testWithAllowedHeadersModifiesConfig(): void
    {
        $headers = ['X-Custom-Header', 'X-Another-Header'];

        $builder = CorsMiddlewareBuilder::create();
        $newBuilder = $builder->withAllowedHeaders($headers);

        // Создаем middleware и проверяем через reflection
        $middleware = $newBuilder->build();
        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('config');
        $reflectionProperty->setAccessible(true);
        $config = $reflectionProperty->getValue($middleware);

        $this->assertEquals($headers, $config['allowed_headers']);
    }

    public function testAddAllowedHeaderModifiesConfig(): void
    {
        $header = 'X-Custom-Header';

        $builder = CorsMiddlewareBuilder::create();
        $newBuilder = $builder->addAllowedHeader($header);

        // Создаем middleware и проверяем через reflection
        $middleware = $newBuilder->build();
        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('config');
        $reflectionProperty->setAccessible(true);
        $config = $reflectionProperty->getValue($middleware);

        $this->assertContains($header, $config['allowed_headers']);
    }

    public function testWithExposedHeadersModifiesConfig(): void
    {
        $headers = ['X-Custom-Header', 'X-Another-Header'];

        $builder = CorsMiddlewareBuilder::create();
        $newBuilder = $builder->withExposedHeaders($headers);

        // Создаем middleware и проверяем через reflection
        $middleware = $newBuilder->build();
        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('config');
        $reflectionProperty->setAccessible(true);
        $config = $reflectionProperty->getValue($middleware);

        $this->assertEquals($headers, $config['exposed_headers']);
    }

    public function testAddExposedHeaderModifiesConfig(): void
    {
        $header = 'X-Custom-Header';

        $builder = CorsMiddlewareBuilder::create();
        $newBuilder = $builder->addExposedHeader($header);

        // Создаем middleware и проверяем через reflection
        $middleware = $newBuilder->build();
        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('config');
        $reflectionProperty->setAccessible(true);
        $config = $reflectionProperty->getValue($middleware);

        $this->assertContains($header, $config['exposed_headers']);
    }

    public function testAllowCredentialsModifiesConfig(): void
    {
        $builder = CorsMiddlewareBuilder::create();
        $newBuilder = $builder->allowCredentials(true);

        // Создаем middleware и проверяем через reflection
        $middleware = $newBuilder->build();
        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('config');
        $reflectionProperty->setAccessible(true);
        $config = $reflectionProperty->getValue($middleware);

        $this->assertTrue($config['allow_credentials']);
    }

    public function testWithMaxAgeModifiesConfig(): void
    {
        $maxAge = 3600;

        $builder = CorsMiddlewareBuilder::create();
        $newBuilder = $builder->withMaxAge($maxAge);

        // Создаем middleware и проверяем через reflection
        $middleware = $newBuilder->build();
        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('config');
        $reflectionProperty->setAccessible(true);
        $config = $reflectionProperty->getValue($middleware);

        $this->assertEquals($maxAge, $config['max_age']);
    }

    public function testRequireExactOriginModifiesConfig(): void
    {
        $builder = CorsMiddlewareBuilder::create();
        $newBuilder = $builder->requireExactOrigin(false);

        // Создаем middleware и проверяем через reflection
        $middleware = $newBuilder->build();
        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('config');
        $reflectionProperty->setAccessible(true);
        $config = $reflectionProperty->getValue($middleware);

        $this->assertFalse($config['require_exact_origin']);
    }

    public function testChainedMethodCalls(): void
    {
        $origins = ['https://example.com'];
        $methods = ['GET', 'POST', 'PUT'];
        $allowedHeaders = ['X-Custom-Header'];
        $exposedHeaders = ['X-Rate-Limit'];
        $maxAge = 7200;

        $middleware = CorsMiddlewareBuilder::create()
            ->withAllowedOrigins($origins)
            ->withAllowedMethods($methods)
            ->withAllowedHeaders($allowedHeaders)
            ->withExposedHeaders($exposedHeaders)
            ->allowCredentials(true)
            ->withMaxAge($maxAge)
            ->requireExactOrigin(false)
            ->build();

        $this->assertInstanceOf(CorsMiddleware::class, $middleware);

        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('config');
        $reflectionProperty->setAccessible(true);
        $config = $reflectionProperty->getValue($middleware);

        $this->assertEquals($origins, $config['allowed_origins']);
        $this->assertEquals($methods, $config['allowed_methods']);
        $this->assertEquals($allowedHeaders, $config['allowed_headers']);
        $this->assertEquals($exposedHeaders, $config['exposed_headers']);
        $this->assertTrue($config['allow_credentials']);
        $this->assertEquals($maxAge, $config['max_age']);
        $this->assertFalse($config['require_exact_origin']);
    }

    public function testAddMethodsPreservesExistingValues(): void
    {
        $origin1 = 'https://example.com';
        $origin2 = 'https://api.example.com';

        $middleware = CorsMiddlewareBuilder::create()
            ->addAllowedOrigin($origin1)
            ->addAllowedOrigin($origin2)
            ->build();

        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('config');
        $reflectionProperty->setAccessible(true);
        $config = $reflectionProperty->getValue($middleware);

        $this->assertCount(2, $config['allowed_origins']);
        $this->assertContains($origin1, $config['allowed_origins']);
        $this->assertContains($origin2, $config['allowed_origins']);
    }

    public function testAddMethodDoesNotDuplicateValues(): void
    {
        $origin = 'https://example.com';

        $middleware = CorsMiddlewareBuilder::create()
            ->addAllowedOrigin($origin)
            ->addAllowedOrigin($origin) // Добавляем тот же origin второй раз
            ->build();

        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('config');
        $reflectionProperty->setAccessible(true);
        $config = $reflectionProperty->getValue($middleware);

        // Убеждаемся, что origin добавлен только один раз
        $this->assertCount(1, $config['allowed_origins']);
        $this->assertContains($origin, $config['allowed_origins']);
    }
}
