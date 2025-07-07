<?php
declare(strict_types=1);

namespace dzentota\Router\Test;

use dzentota\Router\Exception\MethodNotAllowedException;
use dzentota\Router\Exception\NotFoundException;
use dzentota\Router\Router;
use dzentota\TypedValue\Typed;
use dzentota\TypedValue\TypedValue;
use dzentota\TypedValue\ValidationResult;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    public function testShortcuts(): void
    {
        $expected = [
            '/' =>
                [
                    'name' => '/',
                    'delete' => [
                        'name' => 'delete',
                        'exec' => [
                            'route' => '/delete',
                            'method' => [
                                'DELETE' => 'delete',
                            ],
                            'constraints' => [],
                        ],
                    ],
                    'get' => [
                        'name' => 'get',
                        'exec' => [
                            'route' => '/get',
                            'method' => [
                                'GET' => 'get',
                            ],
                            'constraints' => [],
                        ],
                    ],
                    'head' => [
                        'name' => 'head',
                        'exec' => [
                            'route' => '/head',
                            'method' => [
                                'HEAD' => 'head',
                            ],
                            'constraints' => [],
                        ],
                    ],
                    'patch' => [
                        'name' => 'patch',
                        'exec' => [
                            'route' => '/patch',
                            'method' => [
                                'PATCH' => 'patch',
                            ],
                            'constraints' => [],
                        ],
                    ],
                    'post' => [
                        'name' => 'post',
                        'exec' => [
                            'route' => '/post',
                            'method' => [
                                'POST' => 'post',
                            ],
                            'constraints' => [],
                        ],
                    ],
                    'put' => [
                        'name' => 'put',
                        'exec' => [
                            'route' => '/put',
                            'method' => [
                                'PUT' => 'put',
                            ],
                            'constraints' => [],
                        ],
                    ],
                    'options' => [
                        'name' => 'options',
                        'exec' => [
                            'route' => '/options',
                            'method' => [
                                'OPTIONS' => 'options',
                            ],
                            'constraints' => [],
                        ],
                    ],
                ],
        ];

        $r1 = new Router();
        $r2 = new Router();

        $r1->delete('/delete', 'delete');
        $r1->get('/get', 'get');
        $r1->head('/head', 'head');
        $r1->patch('/patch', 'patch');
        $r1->post('/post', 'post');
        $r1->put('/put', 'put');
        $r1->options('/options', 'options');

        $r2->addRoute('DELETE', '/delete', 'delete');
        $r2->addRoute('GET', '/get', 'get');
        $r2->addRoute('HEAD', '/head', 'head');
        $r2->addRoute('PATCH', '/patch', 'patch');
        $r2->addRoute('POST', '/post', 'post');
        $r2->addRoute('PUT', '/put', 'put');
        $r2->addRoute('OPTIONS', '/options', 'options');

        self::assertEquals($expected, $r1->dump());
        self::assertEquals($expected, $r2->dump());
    }

    public function testMultipleMethodsMatch()
    {
        $r1 = new Router();
        $r2 = new Router();

        $r1->post('/', 'index');
        $r1->get('/', 'index');

        $r2->addRoute(['GET', 'POST'], '/', 'index');

        self::assertEquals($r1->dump(), $r2->dump());
    }

    public function testGroups()
    {
        $expected = [
            '/' => [
                'name' => '/',
                'admin' => [
                    'name' => 'admin',
                    'index' => [
                        'name' => 'index',
                        'exec' => [
                            'route' => '/admin/index',
                            'method' => [
                                'GET' => 'index',
                            ],
                            'constraints' => [],
                        ],
                    ],
                    'module' => [
                        'name' => 'module',
                        'get' => [
                            'name' => 'get',
                            'exec' => [
                                'route' => '/admin/module/get',
                                'method' => [
                                    'GET' => 'get',
                                ],
                                'constraints' => [],
                            ],
                        ],
                        'post' => [
                            'name' => 'post',
                            'exec' => [
                                'route' => '/admin/module/post',
                                'method' => [
                                    'POST' => 'post',
                                ],
                                'constraints' => [],
                            ],
                        ],
                        'put' => [
                            'name' => 'put',
                            'exec' => [
                                'route' => '/admin/module/put',
                                'method' => [
                                    'PUT' => 'put',
                                ],
                                'constraints' => [],
                            ],
                        ],
                        'delete' => [
                            'name' => 'delete',
                            'exec' => [
                                'route' => '/admin/module/delete',
                                'method' => [
                                    'DELETE' => 'delete',
                                ],
                                'constraints' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $r = new Router();
        $r->addGroup('/admin', static function (Router $r): void {
            $r->get('/index', 'index');
            $r->addGroup('/module', static function (Router $r): void {
                $r->get('/get', 'get');
                $r->post('/post', 'post');
                $r->put('/put', 'put');
                $r->delete('/delete', 'delete');
            });
        });

        self::assertEquals($expected, $r->dump());
    }

    public function testMatch()
    {
        $r = new Router();

        $r->get('/admin/module', 'AdminModuleIndex');
        $r->addGroup('/admin', function (Router $r) {
            $r->post('/module', 'AdminModuleCreate');
        });

        $index = $r->findRoute('GET', '/admin/module');
        $create = $r->findRoute('POST', '/admin/module');

        self::assertEquals('AdminModuleIndex', $index['action']);
        self::assertEquals('AdminModuleCreate', $create['action']);
    }

    public function testAny()
    {
        $r1 = new Router();
        $r2 = new Router();

        $r1->addRoute('ANY', '/route', 'action');
        $r2->addRoute(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], '/route', 'action');

        self::assertEquals($r1->dump(), $r2->dump());
    }

    public function testPlaceholders()
    {
        $r = new Router();
        $action = 'BlogShow';
        $r->get('/post/{id}', $action, ['id' => Id::class]);

        self::assertEquals($action, $r->findRoute('GET', '/post/42')['action']);
    }

    public function testOptionalPlaceholders()
    {
        $r = new Router();
        $action = 'Action';
        $r->get('/post/{id?}', $action, ['id' => Id::class]);

        self::assertEquals($action, $r->findRoute('GET', '/post')['action']);
        self::assertEquals($action, $r->findRoute('GET', '/post/42')['action']);
    }

    public function testHeadFallback()
    {
        $r = new Router();
        $action = 'Action';
        $r->get('/', $action);

        self::assertEquals($action, $r->findRoute('HEAD', '/')['action']);
    }

    public function testParametersInMiddleOfRoute()
    {
        $r = new Router();
        $action = 'UserUpdateAction';
        $r->get('/user/{id}/update', $action, ['id' => Id::class]);

        $result = $r->findRoute('GET', '/user/42/update');
        self::assertEquals($action, $result['action']);
        self::assertEquals('/user/{id}/update', $result['route']);
        self::assertInstanceOf(Id::class, $result['params']['id']);
        self::assertEquals('42', $result['params']['id']->toNative());
    }

    public function testParametersInMiddleAndEnd()
    {
        $r = new Router();
        $action = 'UserPostUpdateAction';
        $r->get('/user/{userId}/post/{postId}', $action, ['userId' => Id::class, 'postId' => Id::class]);

        $result = $r->findRoute('GET', '/user/42/post/123');
        self::assertEquals($action, $result['action']);
        self::assertEquals('/user/{userId}/post/{postId}', $result['route']);
        self::assertInstanceOf(Id::class, $result['params']['userId']);
        self::assertInstanceOf(Id::class, $result['params']['postId']);
        self::assertEquals('42', $result['params']['userId']->toNative());
        self::assertEquals('123', $result['params']['postId']->toNative());
    }

    public function testNotFound()
    {
        $this->expectException(NotFoundException::class);
        $r = new Router();
        $r->findRoute('GET', '/unknown');
    }

    public function testMethodNotAllowed()
    {
        $this->expectException(MethodNotAllowedException::class);
        $expectedAllowedMethods = ['GET', 'POST'];

        $r = new Router();
        $r->addRoute($expectedAllowedMethods, '/route', 'action');
        $r->findRoute('PATCH', '/route');
    }

    public function testNamedRoutes()
    {
        $r = new Router();
        $r->get('/users', 'UserIndex', [], 'users.index');
        $r->get('/users/{id}', 'UserShow', ['id' => Id::class], 'users.show');
        $r->post('/users', 'UserCreate', [], 'users.create');

        // Test hasRoute
        self::assertTrue($r->hasRoute('users.index'));
        self::assertTrue($r->hasRoute('users.show'));
        self::assertTrue($r->hasRoute('users.create'));
        self::assertFalse($r->hasRoute('users.edit'));

        // Test getNamedRoutes
        $namedRoutes = $r->getNamedRoutes();
        self::assertArrayHasKey('users.index', $namedRoutes);
        self::assertArrayHasKey('users.show', $namedRoutes);
        self::assertArrayHasKey('users.create', $namedRoutes);
        self::assertEquals('/users', $namedRoutes['users.index']['route']);
        self::assertEquals('/users/{id}', $namedRoutes['users.show']['route']);
    }

    public function testGenerateUrlSimple()
    {
        $r = new Router();
        $r->get('/users', 'UserIndex', [], 'users.index');
        $r->post('/contact', 'ContactForm', [], 'contact');

        self::assertEquals('/users', $r->generateUrl('users.index'));
        self::assertEquals('/contact', $r->generateUrl('contact'));
    }

    public function testGenerateUrlWithParameters()
    {
        $r = new Router();
        $r->get('/users/{id}', 'UserShow', ['id' => Id::class], 'users.show');
        $r->get('/users/{userId}/posts/{postId}', 'UserPostShow', 
            ['userId' => Id::class, 'postId' => Id::class], 'users.posts.show');

        self::assertEquals('/users/42', $r->generateUrl('users.show', ['id' => '42']));
        self::assertEquals('/users/123/posts/456', 
            $r->generateUrl('users.posts.show', ['userId' => '123', 'postId' => '456']));
    }

    public function testGenerateUrlWithOptionalParameters()
    {
        $r = new Router();
        $r->get('/posts/{id?}', 'PostIndex', ['id' => Id::class], 'posts.index');
        $r->get('/search/{query?}/{page?}', 'Search', [], 'search');

        // Without optional parameters
        self::assertEquals('/posts', $r->generateUrl('posts.index'));
        self::assertEquals('/search', $r->generateUrl('search'));

        // With optional parameters
        self::assertEquals('/posts/123', $r->generateUrl('posts.index', ['id' => '123']));
        self::assertEquals('/search/test', $r->generateUrl('search', ['query' => 'test']));
        self::assertEquals('/search/test/2', $r->generateUrl('search', ['query' => 'test', 'page' => '2']));
    }

    public function testGenerateUrlInvalidRoute()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Named route 'nonexistent' not found");

        $r = new Router();
        $r->generateUrl('nonexistent');
    }

    public function testGenerateUrlMissingRequiredParameter()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Missing required parameter 'id' for route 'users.show'");

        $r = new Router();
        $r->get('/users/{id}', 'UserShow', ['id' => Id::class], 'users.show');
        $r->generateUrl('users.show');
    }

    public function testGenerateUrlInvalidParameterConstraint()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Parameter 'id' does not match constraint for route 'users.show'");

        $r = new Router();
        $r->get('/users/{id}', 'UserShow', ['id' => Id::class], 'users.show');
        $r->generateUrl('users.show', ['id' => 'invalid']);
    }

    public function testNamedRoutesWithGroups()
    {
        $r = new Router();
        $r->addGroup('/admin', function (Router $r) {
            $r->get('/users', 'AdminUserIndex', [], 'admin.users.index');
            $r->get('/users/{id}', 'AdminUserShow', ['id' => Id::class], 'admin.users.show');
        });

        self::assertTrue($r->hasRoute('admin.users.index'));
        self::assertTrue($r->hasRoute('admin.users.show'));
        
        self::assertEquals('/admin/users', $r->generateUrl('admin.users.index'));
        self::assertEquals('/admin/users/42', $r->generateUrl('admin.users.show', ['id' => '42']));
    }

    public function testNamedRouteConvenienceMethods()
    {
        $r = new Router();
        
        // Test all HTTP methods with names
        $r->get('/users', 'UserIndex', [], 'users.index');
        $r->post('/users', 'UserCreate', [], 'users.create');
        $r->put('/users/{id}', 'UserUpdate', ['id' => Id::class], 'users.update');
        $r->patch('/users/{id}', 'UserPatch', ['id' => Id::class], 'users.patch');
        $r->delete('/users/{id}', 'UserDelete', ['id' => Id::class], 'users.delete');
        $r->head('/users', 'UserHead', [], 'users.head');
        $r->options('/users', 'UserOptions', [], 'users.options');

        self::assertTrue($r->hasRoute('users.index'));
        self::assertTrue($r->hasRoute('users.create'));
        self::assertTrue($r->hasRoute('users.update'));
        self::assertTrue($r->hasRoute('users.patch'));
        self::assertTrue($r->hasRoute('users.delete'));
        self::assertTrue($r->hasRoute('users.head'));
        self::assertTrue($r->hasRoute('users.options'));

        self::assertEquals('/users', $r->generateUrl('users.index'));
        self::assertEquals('/users/123', $r->generateUrl('users.update', ['id' => '123']));
    }
}

class Id implements Typed
{
    use TypedValue;

    public static function validate($value): ValidationResult
    {
        $result = new ValidationResult();
        if (!is_numeric($value) || $value <= 0) {
            $result->addError('Bad ID');
        }
        return $result;
    }
}
