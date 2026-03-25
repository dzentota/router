<?php

declare(strict_types=1);

namespace dzentota\Router\Test;

use dzentota\Router\Attribute\RouteAttribute;
use dzentota\Router\Exception\InvalidConstraintException;
use dzentota\Router\Exception\InvalidRouteException;
use dzentota\Router\Loader\AttributeLoader;
use dzentota\Router\Route;
use dzentota\Router\Router;
use dzentota\Router\UrlSigner;
use PHPUnit\Framework\TestCase;

/**
 * Tests for features added in the second implementation phase:
 *
 *  1. Fluent Route API  — ->where(), ->name(), ->defaults(), ->tag()
 *  2. resource() / apiResource() macros
 *  3. Controller::method string handler
 *  4. Route defaults applied by findRoute()
 *  5. UrlSigner  — sign() / verify()
 *  6. PHP attribute loader  — RouteAttribute / AttributeLoader
 *  7. Auto-naming
 *  8. Route tags  — ->tag(), getRoutesByTag()
 *  9. getStats()
 */
class RouterFeaturesTest extends TestCase
{
    // =========================================================================
    // 1. Fluent Route API
    // =========================================================================

    public function testFluentWhereSetsConstraints(): void
    {
        $r     = new Router();
        $route = $r->get('/users/{id}', 'UserShow')
                   ->where(['id' => RouteId::class]);

        self::assertSame(['id' => RouteId::class], $route->getConstraints());
    }

    public function testFluentWhereValidatesTypedConstraint(): void
    {
        $this->expectException(InvalidConstraintException::class);

        $r = new Router();
        $r->get('/users/{id}', 'UserShow')->where(['id' => 'not-a-typed-class']);
    }

    public function testFluentWhereValidatesNonExistentClass(): void
    {
        $this->expectException(InvalidConstraintException::class);

        $r = new Router();
        $r->get('/users/{id}', 'UserShow')->where(['id' => 'NoSuchClass']);
    }

    public function testFluentNameRegistersRoute(): void
    {
        $r = new Router();
        $r->get('/users/{id}', 'UserShow')
          ->where(['id' => RouteId::class])
          ->name('users.show');

        self::assertTrue($r->hasRoute('users.show'));
        self::assertSame('users.show', $r->findNameForRoute('/users/{id}', 'GET'));
    }

    public function testFluentNameCanBeCalledAfterWhere(): void
    {
        $r = new Router();
        $route = $r->get('/posts/{id}', 'PostShow')
                   ->where(['id' => RouteId::class])
                   ->name('posts.show');

        self::assertSame('posts.show', $route->getName());
        self::assertEquals('/posts/42', $r->generateUrl('posts.show', ['id' => '42']));
    }

    public function testFluentNameOverwrite(): void
    {
        $r = new Router();
        $r->get('/items/{id}', 'ItemShow')
          ->where(['id' => RouteId::class])
          ->name('items.old')
          ->name('items.new');

        self::assertTrue($r->hasRoute('items.new'));
        self::assertFalse($r->hasRoute('items.old'));
    }

    public function testFluentDefaults(): void
    {
        $r     = new Router();
        $route = $r->get('/posts/{id?}', 'PostIndex')
                   ->where(['id' => RouteId::class])
                   ->defaults(['id' => 1])
                   ->name('posts.index');

        self::assertSame(['id' => 1], $route->getDefaults());

        // When the optional segment is absent, the default is parsed through its
        // Typed constraint — the handler receives a RouteId object, not a raw int.
        $result = $r->findRoute('GET', '/posts');
        self::assertInstanceOf(RouteId::class, $result['params']['id']);
        self::assertSame('1', $result['params']['id']->toNative());
    }

    public function testFluentTag(): void
    {
        $r     = new Router();
        $route = $r->get('/api/users', 'ApiUserIndex')
                   ->tag('api')
                   ->tag(['public', 'api']); // duplicate 'api' must be ignored

        self::assertSame(['api', 'public'], $route->getTags());
    }

    public function testAddRouteReturnsMutableRouteObject(): void
    {
        $r     = new Router();
        $route = $r->addRoute('GET', '/ping', 'PingAction');

        self::assertInstanceOf(Route::class, $route);
    }

    // =========================================================================
    // 2. resource() macro
    // =========================================================================

    public function testResourceRegistersSevenRoutes(): void
    {
        $r = new Router();
        $r->resource('/posts', PostController::class, ['id' => RouteId::class]);

        // All seven named routes must exist.
        foreach (['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'] as $action) {
            self::assertTrue($r->hasRoute('posts.' . $action), "Missing route posts.{$action}");
        }

        // Correct URL generation.
        self::assertSame('/posts',           $r->generateUrl('posts.index'));
        self::assertSame('/posts/create',    $r->generateUrl('posts.create'));
        self::assertSame('/posts/42',        $r->generateUrl('posts.show',    ['id' => '42']));
        self::assertSame('/posts/42/edit',   $r->generateUrl('posts.edit',    ['id' => '42']));
        self::assertSame('/posts/42',        $r->generateUrl('posts.update',  ['id' => '42']));
        self::assertSame('/posts/42',        $r->generateUrl('posts.destroy', ['id' => '42']));

        // Compound-key reverse lookup: same URI pattern resolves to correct name per method.
        self::assertSame('posts.show',    $r->findNameForRoute('/posts/{id}', 'GET'));
        self::assertSame('posts.update',  $r->findNameForRoute('/posts/{id}', 'PUT'));
        self::assertSame('posts.update',  $r->findNameForRoute('/posts/{id}', 'PATCH'));
        self::assertSame('posts.destroy', $r->findNameForRoute('/posts/{id}', 'DELETE'));
    }

    public function testResourceRejectsEmptyIdConstraint(): void
    {
        $this->expectException(\dzentota\Router\Exception\InvalidConstraintException::class);

        $r = new Router();
        $r->resource('/posts', PostController::class);  // no idConstraint
    }

    public function testApiResourceRejectsEmptyIdConstraint(): void
    {
        $this->expectException(\dzentota\Router\Exception\InvalidConstraintException::class);

        $r = new Router();
        $r->apiResource('/comments', PostController::class);
    }

    public function testResourceAcceptsGetAndPatchForUpdate(): void
    {
        $r = new Router();
        $r->resource('/posts', PostController::class, ['id' => RouteId::class]);

        $put   = $r->findRoute('PUT',   '/posts/1');
        $patch = $r->findRoute('PATCH', '/posts/1');

        self::assertSame([PostController::class, 'update'], $put['action']);
        self::assertSame([PostController::class, 'update'], $patch['action']);
    }

    public function testResourceNamePrefixFromNestedGroup(): void
    {
        $r = new Router();
        $r->addGroup('/admin', function (Router $r) {
            $r->resource('/users', PostController::class, ['id' => RouteId::class]);
        });

        self::assertTrue($r->hasRoute('admin.users.index'));
        self::assertTrue($r->hasRoute('admin.users.show'));
        self::assertSame('/admin/users',    $r->generateUrl('admin.users.index'));
        self::assertSame('/admin/users/99', $r->generateUrl('admin.users.show', ['id' => '99']));
    }

    // =========================================================================
    // 3. apiResource() macro
    // =========================================================================

    public function testApiResourceRegistersFiveRoutes(): void
    {
        $r = new Router();
        $r->apiResource('/comments', PostController::class, ['id' => RouteId::class]);

        foreach (['index', 'store', 'show', 'update', 'destroy'] as $action) {
            self::assertTrue($r->hasRoute('comments.' . $action), "Missing route comments.{$action}");
        }

        // HTML-form routes must NOT be registered.
        self::assertFalse($r->hasRoute('comments.create'));
        self::assertFalse($r->hasRoute('comments.edit'));
    }

    // =========================================================================
    // 4. Route defaults
    // =========================================================================

    public function testDefaultsAppliedWhenOptionalParamAbsent(): void
    {
        $r = new Router();
        $r->get('/items/{page?}', 'ItemList')
          ->where(['page' => RouteId::class])
          ->defaults(['page' => 1])
          ->name('items.index');

        $result = $r->findRoute('GET', '/items');
        // Default is parsed through RouteId — always a Typed object, never a raw value.
        self::assertInstanceOf(RouteId::class, $result['params']['page']);
        self::assertSame('1', $result['params']['page']->toNative());
    }

    public function testDefaultsMergedAcrossMethodsOnSamePattern(): void
    {
        $r = new Router();
        $r->get('/things/{page?}', 'ThingList')
          ->where(['page' => RouteId::class])
          ->defaults(['page' => 1]);
        $r->post('/things/{page?}', 'ThingStore')
          ->where(['page' => RouteId::class])
          ->defaults(['page' => 99]);

        $get  = $r->findRoute('GET',  '/things');
        $post = $r->findRoute('POST', '/things');

        // Each method gets its own default, parsed through the constraint.
        self::assertInstanceOf(RouteId::class, $get['params']['page']);
        self::assertSame('1',  $get['params']['page']->toNative());
        self::assertInstanceOf(RouteId::class, $post['params']['page']);
        self::assertSame('99', $post['params']['page']->toNative());
    }

    public function testDefaultWithoutConstraintThrows(): void
    {
        $this->expectException(\dzentota\Router\Exception\InvalidConstraintException::class);
        $this->expectExceptionMessage("Default for 'page' on '/items/{page?}' has no Typed constraint");

        $r = new Router();
        $r->get('/items/{page?}', 'ItemList')
          ->defaults(['page' => 1]); // no ->where() — must throw at tree-build time
        $r->findRoute('GET', '/items'); // triggers parseRoutes()
    }

    public function testDefaultWithInvalidValueThrows(): void
    {
        $this->expectException(\dzentota\Router\Exception\InvalidRouteException::class);
        $this->expectExceptionMessage("Default value 'bad' for 'page'");

        $r = new Router();
        $r->get('/items/{page?}', 'ItemList')
          ->where(['page' => RouteId::class])
          ->defaults(['page' => 'bad']); // 'bad' is not a valid RouteId
        $r->findRoute('GET', '/items'); // triggers parseRoutes()
    }

    public function testDefaultsNotAppliedWhenParamPresent(): void
    {
        $r = new Router();
        $r->get('/items/{page?}', 'ItemList')
          ->where(['page' => RouteId::class])
          ->defaults(['page' => 1])
          ->name('items.index');

        $result = $r->findRoute('GET', '/items/3');
        self::assertInstanceOf(RouteId::class, $result['params']['page']);
        self::assertSame('3', $result['params']['page']->toNative());
    }

    // =========================================================================
    // 5. UrlSigner
    // =========================================================================

    public function testUrlSignerProducesVerifiableUrl(): void
    {
        $r      = new Router();
        $r->get('/reports/{id}', 'ReportShow', ['id' => RouteId::class], 'reports.show');
        $signer = new UrlSigner($r, 'test-signing-key-32-chars-long!!');

        $signed = $signer->sign('reports.show', ['id' => '7']);

        self::assertStringContainsString('?expires=', $signed);
        self::assertStringContainsString('&signature=', $signed);
        self::assertTrue($signer->verify($signed));
    }

    public function testUrlSignerRejectsExpiredUrl(): void
    {
        $r      = new Router();
        $r->get('/dl/{id}', 'Download', ['id' => RouteId::class], 'dl.get');
        $signer = new UrlSigner($r, 'test-signing-key-32-chars-long!!');

        // Sign with a TTL that expired in the past.
        $signed = $signer->sign('dl.get', ['id' => '1'], -10);

        self::assertFalse($signer->verify($signed));
    }

    public function testUrlSignerRejectsTamperedSignature(): void
    {
        $r      = new Router();
        $r->get('/export/{id}', 'Export', ['id' => RouteId::class], 'export.get');
        $signer = new UrlSigner($r, 'test-signing-key-32-chars-long!!');

        $signed  = $signer->sign('export.get', ['id' => '5']);
        $tampered = preg_replace('/signature=[a-f0-9]+/', 'signature=deadbeef', $signed);

        self::assertFalse($signer->verify($tampered));
    }

    public function testUrlSignerRejectsMissingSignature(): void
    {
        $r      = new Router();
        $r->get('/x', 'X', [], 'x');
        $signer = new UrlSigner($r, 'test-signing-key-32-chars-long!!');

        self::assertFalse($signer->verify('/x?expires=9999999999'));
    }

    public function testUrlSignerRejectsShortKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $r = new Router();
        new UrlSigner($r, 'short');
    }

    public function testUrlSignerCustomTtl(): void
    {
        $r      = new Router();
        $r->get('/link', 'Link', [], 'link');
        $signer = new UrlSigner($r, 'test-signing-key-32-chars-long!!', 7200);

        $signed = $signer->sign('link', [], 100); // custom TTL overrides default

        $qPos = strpos($signed, '?');
        parse_str(substr($signed, $qPos + 1), $query);
        $expires = (int)$query['expires'];

        // Should expire ~100 seconds from now, not 7200.
        self::assertGreaterThan(time() + 90, $expires);
        self::assertLessThan(time() + 110, $expires);
    }

    // =========================================================================
    // 6. PHP Attribute loader
    // =========================================================================

    public function testAttributeLoaderLoadsMethodLevelRoutes(): void
    {
        $r = new Router();
        (new AttributeLoader($r))->loadFromClass(AttrController::class);

        self::assertTrue($r->hasRoute('attr.list'));
        self::assertTrue($r->hasRoute('attr.show'));
        self::assertSame('/attr/items',    $r->generateUrl('attr.list'));
        self::assertSame('/attr/items/3',  $r->generateUrl('attr.show', ['id' => '3']));
    }

    public function testAttributeLoaderAppliesClassLevelPrefix(): void
    {
        $r = new Router();
        (new AttributeLoader($r))->loadFromClass(PrefixedAttrController::class);

        self::assertTrue($r->hasRoute('prefixed.list'));
        $result = $r->findRoute('GET', '/v2/items');
        self::assertSame([PrefixedAttrController::class, 'list'], $result['action']);
    }

    public function testAttributeLoaderAppliesDefaultsAndTags(): void
    {
        $r = new Router();
        (new AttributeLoader($r))->loadFromClass(AttrController::class);

        $routes = $r->getRoutesByTag('api');
        self::assertNotEmpty($routes);
    }

    // =========================================================================
    // 7. Auto-naming
    // =========================================================================

    public function testAutoNamingGeneratesNameForSingleMethodRoute(): void
    {
        $r = new Router();
        $r->enableAutoNaming();
        $r->get('/dashboard', 'Dashboard');

        self::assertTrue($r->hasRoute('dashboard.get'));
        self::assertSame('/dashboard', $r->generateUrl('dashboard.get'));
    }

    public function testAutoNamingUsesParamNamesInPattern(): void
    {
        $r = new Router();
        $r->enableAutoNaming();
        $r->get('/users/{id}', 'UserShow', ['id' => RouteId::class]);

        self::assertTrue($r->hasRoute('users.id.get'));
    }

    public function testAutoNamingDoesNotOverrideExplicitName(): void
    {
        $r = new Router();
        $r->enableAutoNaming();
        $r->get('/profile', 'Profile', [], 'my.profile');

        self::assertTrue($r->hasRoute('my.profile'));
        self::assertFalse($r->hasRoute('profile.get'));
    }

    public function testAutoNamingDisabledByDefault(): void
    {
        $r = new Router();
        $r->get('/noop', 'Noop');

        self::assertFalse($r->hasRoute('noop.get'));
    }

    public function testAutoNamingCanBeDisabled(): void
    {
        $r = new Router();
        $r->enableAutoNaming()->disableAutoNaming();
        $r->get('/noop', 'Noop');

        self::assertFalse($r->hasRoute('noop.get'));
    }

    public function testAutoNamingRootRoute(): void
    {
        $r = new Router();
        $r->enableAutoNaming();
        $r->get('/', 'Home');

        self::assertTrue($r->hasRoute('root.get'));
    }

    // =========================================================================
    // 8. Route tags & getRoutesByTag()
    // =========================================================================

    public function testGetRoutesByTag(): void
    {
        $r = new Router();
        $r->get('/a', 'A')->tag('api');
        $r->get('/b', 'B')->tag(['api', 'public']);
        $r->get('/c', 'C')->tag('admin');

        $apiRoutes  = $r->getRoutesByTag('api');
        $adminRoutes = $r->getRoutesByTag('admin');
        $noneRoutes  = $r->getRoutesByTag('nonexistent');

        self::assertCount(2, $apiRoutes);
        self::assertCount(1, $adminRoutes);
        self::assertCount(0, $noneRoutes);
    }

    public function testTagDuplicatesIgnored(): void
    {
        $r     = new Router();
        $route = $r->get('/dup', 'Dup')->tag('api')->tag('api')->tag(['api', 'public']);

        self::assertSame(['api', 'public'], $route->getTags());
    }

    // =========================================================================
    // 9. getStats()
    // =========================================================================

    public function testGetStatsEmpty(): void
    {
        $r     = new Router();
        $stats = $r->getStats();

        self::assertSame(0, $stats['total']);
        self::assertSame(0, $stats['named']);
        self::assertSame(0, $stats['tagged']);
        self::assertSame([], $stats['methods']);
        self::assertSame([], $stats['tags']);
    }

    public function testGetStatsCounts(): void
    {
        $r = new Router();
        $r->get('/a', 'A')->name('a')->tag('api');
        $r->post('/b', 'B')->name('b');
        $r->get('/c', 'C')->tag('public');
        $r->addRoute(['GET', 'POST'], '/d', 'D');

        $stats = $r->getStats();

        self::assertSame(4, $stats['total']);
        self::assertSame(2, $stats['named']);
        self::assertSame(2, $stats['tagged']);
        self::assertSame(1, $stats['tags']['api']);
        self::assertSame(1, $stats['tags']['public']);
        // /d registers one Route object that handles both methods
        self::assertSame(3, $stats['methods']['GET']);
        self::assertSame(2, $stats['methods']['POST']);
    }

    // =========================================================================
    // 10. Controller::method string format
    // =========================================================================

    public function testRouteActionCanBeDoubleColonString(): void
    {
        // Just verify the router stores the action string as-is.
        $r = new Router();
        $r->get('/invoke', 'SomeController::handle');

        $result = $r->findRoute('GET', '/invoke');
        self::assertSame('SomeController::handle', $result['action']);
    }

    // =========================================================================
    // exportCache() / importCache()
    // =========================================================================

    public function testExportCacheProducesValidJson(): void
    {
        $r = new Router();
        $r->get('/users/{id}', 'UserController@show')
          ->where(['id' => RouteId::class])
          ->name('users.show')
          ->tag('api');

        $json = $r->exportCache();
        $data = json_decode($json, true);

        self::assertSame(1, $data['version']);
        self::assertCount(1, $data['routes']);
        $entry = $data['routes'][0];
        self::assertSame(['GET'], $entry['methods']);
        self::assertSame('/users/{id}', $entry['pattern']);
        self::assertSame('UserController@show', $entry['handler']);
        self::assertSame(['id' => RouteId::class], $entry['constraints']);
        self::assertSame('users.show', $entry['name']);
        self::assertSame(['api'], $entry['tags']);
    }

    public function testExportCacheThrowsForClosureHandler(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Closure/');

        $r = new Router();
        $r->get('/hello', fn() => 'hi');
        $r->exportCache();
    }

    public function testImportCacheRestoresRoutes(): void
    {
        $original = new Router();
        $original->get('/users/{id}', 'UserController@show')
                 ->where(['id' => RouteId::class])
                 ->name('users.show')
                 ->tag('api');
        $original->post('/users', 'UserController@store');

        $json = $original->exportCache();

        $restored = new Router();
        $restored->importCache($json);

        // Named route preserved
        self::assertSame('/users/42', $restored->generateUrl('users.show', ['id' => '42']));

        // Both routes are matchable
        $get  = $restored->findRoute('GET', '/users/42');
        self::assertSame('UserController@show', $get['action']);
        self::assertInstanceOf(RouteId::class, $get['params']['id']);

        $post = $restored->findRoute('POST', '/users');
        self::assertSame('UserController@store', $post['action']);
    }

    public function testImportCacheWithArrayHandler(): void
    {
        $original = new Router();
        $original->addRoute('GET', '/items', [PostController::class, 'index']);

        $json     = $original->exportCache();
        $restored = new Router();
        $restored->importCache($json);

        $result = $restored->findRoute('GET', '/items');
        self::assertSame([PostController::class, 'index'], $result['action']);
    }

    public function testImportCacheReplacesExistingRoutes(): void
    {
        $original = new Router();
        $original->get('/new', 'NewController@index');
        $json = $original->exportCache();

        $router = new Router();
        $router->get('/old', 'OldController@index');

        $router->importCache($json);

        // Old route gone
        $this->expectException(\dzentota\Router\Exception\NotFoundException::class);
        $router->findRoute('GET', '/old');
    }

    public function testImportCacheThrowsOnInvalidJson(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/invalid JSON/i');

        (new Router())->importCache('not-json{{{');
    }

    public function testImportCacheThrowsOnWrongVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/version 1/i');

        (new Router())->importCache(json_encode(['version' => 99, 'routes' => []]));
    }

    public function testImportCacheThrowsOnMissingKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/missing key/i');

        $payload = json_encode(['version' => 1, 'routes' => [
            ['methods' => ['GET'], 'pattern' => '/x', 'handler' => 'Ctrl@act'],
            // missing: constraints, defaults, name, tags
        ]]);
        (new Router())->importCache($payload);
    }

    public function testImportCacheThrowsOnClosureInHandler(): void
    {
        // JSON cannot contain closures — an 'object' handler would be invalid
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/handler/i');

        $payload = json_encode(['version' => 1, 'routes' => [
            ['methods' => ['GET'], 'pattern' => '/x', 'handler' => ['only-one-element'],
             'constraints' => [], 'defaults' => [], 'name' => null, 'tags' => []],
        ]]);
        (new Router())->importCache($payload);
    }

    public function testExportImportRoundTripWithDefaults(): void
    {
        $original = new Router();
        $original->get('/page/{page}', 'PageController@show')
                 ->where(['page' => RouteId::class])
                 ->defaults(['page' => '1']);

        $restored = new Router();
        $restored->importCache($original->exportCache());

        $result = $restored->findRoute('GET', '/page/5');
        self::assertInstanceOf(RouteId::class, $result['params']['page']);
    }
}

// ---------------------------------------------------------------------------
// Local test fixtures
// ---------------------------------------------------------------------------

class RouteId implements \dzentota\TypedValue\Typed
{
    use \dzentota\TypedValue\TypedValue;

    public static function validate($value): \dzentota\TypedValue\ValidationResult
    {
        $res = new \dzentota\TypedValue\ValidationResult();
        if (!is_numeric($value) || (int)$value <= 0) {
            $res->addError('Invalid ID');
        }
        return $res;
    }
}

class PostController
{
    public function index(): string  { return 'index'; }
    public function create(): string { return 'create'; }
    public function store(): string  { return 'store'; }
    public function show(): string   { return 'show'; }
    public function edit(): string   { return 'edit'; }
    public function update(): string { return 'update'; }
    public function destroy(): string { return 'destroy'; }
}

#[RouteAttribute('/attr')]
class AttrController
{
    #[RouteAttribute('/items', methods: 'GET', name: 'attr.list', tags: ['api'])]
    public function list(): string { return 'list'; }

    #[RouteAttribute('/items/{id}', methods: 'GET', name: 'attr.show', constraints: ['id' => RouteId::class])]
    public function show(): string { return 'show'; }
}

#[RouteAttribute('/v2')]
class PrefixedAttrController
{
    #[RouteAttribute('/items', methods: 'GET', name: 'prefixed.list')]
    public function list(): string { return 'list'; }
}
