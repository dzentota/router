## Fast and flexible security aware router.


### Usage
Usage of Router is as simple as:

```php
// Fetch method and URI from somewhere
$httpMethod = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

// Strip query string (?foo=bar) and decode URI
if (false !== $pos = strpos($uri, '?')) {
    $uri = substr($uri, 0, $pos);
}
$uri = rawurldecode($uri);

$route = (new Router())
    ->get('/user/{id}', 'UserController@show', ['id' => Id::class])
    ->findRoute($httpMethod, $uri);
```
The resolved `$route` will have the following structure (for `GET /user/42`):
```
array(4) {
  ["route"]=>
  string(10) "/user/{id}"
  ["method"]=>
  string(3) "get"
  ["action"]=>
  string(19) "UserController@show"
  ["params"]=>
  array(1) {
    ["id"]=>
    object(Id)#4 (1) {
      ["value":protected]=>
      string(2) "42"
    }
  }
}
```


### Defining routes
The routes are added by calling `addRoute()` on the Router instance:

```php
$r->addRoute($method, string $route, string $action, array $constraints = [], ?string $name = null);
```
The $method is an HTTP method string for which a certain route should match. It is possible to specify multiple valid methods using an array:
```php
// These two calls
$r->addRoute('GET', '/test', 'handler');
$r->addRoute('POST', '/test', 'handler');
// Are equivalent to this one call
$r->addRoute(['GET', 'POST'], '/test', 'handler');
```
Router uses a syntax where `{foo}` specifies a placeholder with name `foo`. Every placeholder in the route must be typed. 
From security perspective, there is no sense in accepting data from the user (via HTTP) without properly validating it against your domain.

Assume we have a list of users stored in the database and these users may be retrieved by the ID that is a autoincrement
positive integer. In such a case it's a good idea to introduce a domain primitive - `ID`:

```php
<?php

use dzentota\TypedValue\Typed;
use dzentota\TypedValue\TypedValue;
use dzentota\TypedValue\ValidationResult;

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
```

Now you can use ID as a custom type for placeholder in the route

```php
$r->get('/user/{id}', 'UserController@show', ['id' => Id::class])
```

Params of the route enclosed in `{...?}` are considered optional, so that `/foo/{bar?}` will match both `/foo` and `/foo/bar`.

### Named Routes

Routes can be given names for easy URL generation in templates and redirects:

```php
// Define named routes
$r->get('/users', 'UserController@index', [], 'users.index');
$r->get('/users/{id}', 'UserController@show', ['id' => Id::class], 'users.show');
$r->post('/users', 'UserController@create', [], 'users.create');
```

### URL Generation

Generate URLs from named routes using the `generateUrl()` method:

```php
// Simple routes without parameters
$url = $r->generateUrl('users.index'); // Returns: /users

// Routes with parameters
$url = $r->generateUrl('users.show', ['id' => '42']); // Returns: /users/42

// Routes with optional parameters
$r->get('/posts/{category?}', 'PostController@index', [], 'posts.index');
$url1 = $r->generateUrl('posts.index'); // Returns: /posts
$url2 = $r->generateUrl('posts.index', ['category' => 'tech']); // Returns: /posts/tech
```

The router validates parameters against their constraints before generating URLs:

```php
// This will throw an exception if 'invalid' doesn't pass Id validation
$url = $r->generateUrl('users.show', ['id' => 'invalid']);
```

### Route Inspection

You can inspect and work with named routes:

```php
// Check if a named route exists
if ($r->hasRoute('users.show')) {
    // Route exists
}

// Get all named routes
$namedRoutes = $r->getNamedRoutes();
```

### Shortcut methods for common request methods
For the `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `OPTIONS` and `HEAD` request methods shortcut methods are available. 
For example:
```php
$r->get('/get-route', 'get_handler');
$r->post('/post-route', 'post_handler');

// With names
$r->get('/users', 'UserController@index', [], 'users.index');
$r->post('/users', 'UserController@create', [], 'users.create');
```
Is equivalent to:
```php
$r->addRoute('GET', '/get-route', 'get_handler');
$r->addRoute('POST', '/post-route', 'post_handler');

// With names
$r->addRoute('GET', '/users', 'UserController@index', [], 'users.index');
$r->addRoute('POST', '/users', 'UserController@create', [], 'users.create');
```
Also, there is a virtual `ANY` method that matches any request method, so:

```php
$r->addRoute('ANY', '/route', 'get_handler');
```
Is equivalent to:
```php
$r->addRoute(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], '/route', 'get_handler');
```
### Route Groups
Additionally, you can specify routes inside a group. All routes defined inside a group will have a common prefix.

For example, defining your routes as:

```php
$r->addGroup('/admin', function (Router $r) {
    $r->addRoute('GET', '/do-something', 'handler');
    $r->addRoute('GET', '/do-another-thing', 'handler');
    $r->addRoute('GET', '/do-something-else', 'handler');
});
```

Will have the same result as:

```php
$r->addRoute('GET', '/admin/do-something', 'handler');
$r->addRoute('GET', '/admin/do-another-thing', 'handler');
$r->addRoute('GET', '/admin/do-something-else', 'handler');
```

Named routes work with groups as well:

```php
$r->addGroup('/admin', function (Router $r) {
    $r->get('/users', 'AdminController@users', [], 'admin.users');
    $r->get('/settings', 'AdminController@settings', [], 'admin.settings');
});

// Generate URLs
$usersUrl = $r->generateUrl('admin.users'); // Returns: /admin/users
$settingsUrl = $r->generateUrl('admin.settings'); // Returns: /admin/settings
```

### Caching
You can dump and save routes using `dump()`, so later you can load them with `load()`
Save routes:
```php
file_put_contents('routes.php', sprintf('<?php return %s;',  var_export($r->dump(), true)));
```
Restore routes:
```php
$routes = require 'routes.php';
$r->load($routes);
```
### A Note on HEAD Requests
The HTTP spec requires servers to support both GET and HEAD methods:

> The methods GET and HEAD MUST be supported by all general-purpose servers

To avoid forcing users to manually register HEAD routes for each resource we fallback to matching an available GET route for a given resource.
The PHP web SAPI transparently removes the entity body from HEAD responses so this behavior has no effect on the vast majority of users. 
Of course, you can always specify a custom handler for HEAD method 