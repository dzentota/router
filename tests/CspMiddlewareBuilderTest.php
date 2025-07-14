<?php
declare(strict_types=1);

namespace dzentota\Router\Tests;

use dzentota\Router\Middleware\Builder\CspMiddlewareBuilder;
use dzentota\Router\Middleware\Contract\TokenGeneratorInterface;
use dzentota\Router\Middleware\CspMiddleware;
use dzentota\Router\Middleware\Security\TokenGenerator;
use PHPUnit\Framework\TestCase;

class CspMiddlewareBuilderTest extends TestCase
{
    public function testCreateReturnsBuilder(): void
    {
        $builder = CspMiddlewareBuilder::create();

        $this->assertInstanceOf(CspMiddlewareBuilder::class, $builder);
    }

    public function testBuildCreatesCspMiddleware(): void
    {
        $middleware = CspMiddlewareBuilder::create()->build();

        $this->assertInstanceOf(CspMiddleware::class, $middleware);
    }

    public function testWithDirectiveModifiesDirective(): void
    {
        $directive = 'script-src';
        $sources = ['https://example.com', "'self'"];

        $builder = CspMiddlewareBuilder::create();
        $newBuilder = $builder->withDirective($directive, $sources);

        // Проверяем, что возвращается новый экземпляр
        $this->assertNotSame($builder, $newBuilder);

        // Создаем middleware и проверяем через reflection
        $middleware = $newBuilder->build();
        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('directives');
        $reflectionProperty->setAccessible(true);
        $directives = $reflectionProperty->getValue($middleware);

        $this->assertEquals($sources, $directives[$directive]);
    }

    public function testWithDirectivesModifiesMultipleDirectives(): void
    {
        $newDirectives = [
            'script-src' => ['https://example.com', "'self'"],
            'style-src' => ['https://fonts.googleapis.com', "'self'"]
        ];

        $builder = CspMiddlewareBuilder::create();
        $newBuilder = $builder->withDirectives($newDirectives);

        // Проверяем, что возвращается новый экземпляр
        $this->assertNotSame($builder, $newBuilder);

        // Создаем middleware и проверяем через reflection
        $middleware = $newBuilder->build();
        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('directives');
        $reflectionProperty->setAccessible(true);
        $directives = $reflectionProperty->getValue($middleware);

        foreach ($newDirectives as $directive => $sources) {
            $this->assertEquals($sources, $directives[$directive]);
        }
    }

    public function testReportOnlySetsReportOnlyFlag(): void
    {
        $builder = CspMiddlewareBuilder::create();
        $newBuilder = $builder->reportOnly(true);

        // Проверяем, что возвращается новый экземпляр
        $this->assertNotSame($builder, $newBuilder);

        // Создаем middleware и проверяем через reflection
        $middleware = $newBuilder->build();
        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('reportOnly');
        $reflectionProperty->setAccessible(true);

        $this->assertTrue($reflectionProperty->getValue($middleware));
    }

    public function testWithReportUriSetsReportUri(): void
    {
        $reportUri = 'https://example.com/report';

        $builder = CspMiddlewareBuilder::create();
        $newBuilder = $builder->withReportUri($reportUri);

        // Проверяем, что возвращается новый экземпляр
        $this->assertNotSame($builder, $newBuilder);

        // Создаем middleware и проверяем через reflection
        $middleware = $newBuilder->build();
        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('reportUri');
        $reflectionProperty->setAccessible(true);

        $this->assertEquals($reportUri, $reflectionProperty->getValue($middleware));
    }

    public function testWithNonceModifiesNonceFlag(): void
    {
        $builder = CspMiddlewareBuilder::create();
        $newBuilder = $builder->withNonce(false);

        // Проверяем, что возвращается новый экземпляр
        $this->assertNotSame($builder, $newBuilder);

        // Создаем middleware и проверяем через reflection
        $middleware = $newBuilder->build();
        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('generateNonce');
        $reflectionProperty->setAccessible(true);

        $this->assertFalse($reflectionProperty->getValue($middleware));
    }

    public function testWithTokenGeneratorSetsGenerator(): void
    {
        $tokenGenerator = $this->createMock(TokenGeneratorInterface::class);

        $builder = CspMiddlewareBuilder::create();
        $newBuilder = $builder->withTokenGenerator($tokenGenerator);

        // Проверяем, что возвращается новый экземпляр
        $this->assertNotSame($builder, $newBuilder);

        // Создаем middleware и проверяем через reflection
        $middleware = $newBuilder->build();
        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('tokenGenerator');
        $reflectionProperty->setAccessible(true);

        $this->assertSame($tokenGenerator, $reflectionProperty->getValue($middleware));
    }

    public function testAllowInlineScriptsModifiesDirective(): void
    {
        $builder = CspMiddlewareBuilder::create();
        $newBuilder = $builder->allowInlineScripts();

        // Создаем middleware и проверяем через reflection
        $middleware = $newBuilder->build();
        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('directives');
        $reflectionProperty->setAccessible(true);
        $directives = $reflectionProperty->getValue($middleware);

        $this->assertContains("'unsafe-inline'", $directives['script-src']);
    }

    public function testAllowInlineStylesModifiesDirective(): void
    {
        $builder = CspMiddlewareBuilder::create();
        $newBuilder = $builder->allowInlineStyles();

        // Создаем middleware и проверяем через reflection
        $middleware = $newBuilder->build();
        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('directives');
        $reflectionProperty->setAccessible(true);
        $directives = $reflectionProperty->getValue($middleware);

        $this->assertContains("'unsafe-inline'", $directives['style-src']);
    }

    public function testAllowEvalModifiesDirective(): void
    {
        $builder = CspMiddlewareBuilder::create();
        $newBuilder = $builder->allowEval();

        // Создаем middleware и проверяем через reflection
        $middleware = $newBuilder->build();
        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('directives');
        $reflectionProperty->setAccessible(true);
        $directives = $reflectionProperty->getValue($middleware);

        $this->assertContains("'unsafe-eval'", $directives['script-src']);
    }

    public function testAllowScriptFromModifiesDirective(): void
    {
        $domain = 'https://cdn.example.com';

        $builder = CspMiddlewareBuilder::create();
        $newBuilder = $builder->allowScriptFrom($domain);

        // Создаем middleware и проверяем через reflection
        $middleware = $newBuilder->build();
        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('directives');
        $reflectionProperty->setAccessible(true);
        $directives = $reflectionProperty->getValue($middleware);

        $this->assertContains($domain, $directives['script-src']);
    }

    public function testAllowStyleFromModifiesDirective(): void
    {
        $domain = 'https://styles.example.com';

        $builder = CspMiddlewareBuilder::create();
        $newBuilder = $builder->allowStyleFrom($domain);

        // Создаем middleware и проверяем через reflection
        $middleware = $newBuilder->build();
        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('directives');
        $reflectionProperty->setAccessible(true);
        $directives = $reflectionProperty->getValue($middleware);

        $this->assertContains($domain, $directives['style-src']);
    }

    public function testAllowImageFromModifiesDirective(): void
    {
        $domain = 'https://images.example.com';

        $builder = CspMiddlewareBuilder::create();
        $newBuilder = $builder->allowImageFrom($domain);

        // Создаем middleware и проверяем через reflection
        $middleware = $newBuilder->build();
        $reflectionClass = new \ReflectionClass($middleware);
        $reflectionProperty = $reflectionClass->getProperty('directives');
        $reflectionProperty->setAccessible(true);
        $directives = $reflectionProperty->getValue($middleware);

        $this->assertContains($domain, $directives['img-src']);
    }

    public function testChainedMethodCalls(): void
    {
        $reportUri = 'https://example.com/report';
        $scriptDomain = 'https://cdn.example.com';
        $styleDomain = 'https://fonts.googleapis.com';
        $tokenGenerator = new TokenGenerator();

        $middleware = CspMiddlewareBuilder::create()
            ->withReportUri($reportUri)
            ->reportOnly(true)
            ->allowScriptFrom($scriptDomain)
            ->allowStyleFrom($styleDomain)
            ->allowInlineScripts()
            ->withNonce(true)
            ->withTokenGenerator($tokenGenerator)
            ->build();

        $this->assertInstanceOf(CspMiddleware::class, $middleware);

        $reflectionClass = new \ReflectionClass($middleware);

        $reportUriProp = $reflectionClass->getProperty('reportUri');
        $reportUriProp->setAccessible(true);
        $this->assertEquals($reportUri, $reportUriProp->getValue($middleware));

        $reportOnlyProp = $reflectionClass->getProperty('reportOnly');
        $reportOnlyProp->setAccessible(true);
        $this->assertTrue($reportOnlyProp->getValue($middleware));

        $directivesProp = $reflectionClass->getProperty('directives');
        $directivesProp->setAccessible(true);
        $directives = $directivesProp->getValue($middleware);

        $this->assertContains($scriptDomain, $directives['script-src']);
        $this->assertContains($styleDomain, $directives['style-src']);
        $this->assertContains("'unsafe-inline'", $directives['script-src']);

        $nonceProp = $reflectionClass->getProperty('generateNonce');
        $nonceProp->setAccessible(true);
        $this->assertTrue($nonceProp->getValue($middleware));

        $tokenGeneratorProp = $reflectionClass->getProperty('tokenGenerator');
        $tokenGeneratorProp->setAccessible(true);
        $this->assertSame($tokenGenerator, $tokenGeneratorProp->getValue($middleware));
    }
}
