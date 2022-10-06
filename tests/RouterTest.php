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
