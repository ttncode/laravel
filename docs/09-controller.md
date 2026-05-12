# Step 09: Controllers

---

## 🚩 The Problem

Currently, our `routes/web.php` uses closures (anonymous functions) for routing:

```php
$router->get('/users', function () {
    // 1. Fetch users from database
    // 2. Format data
    // 3. Return response
});
```

**Why is this bad?**
1. **Bloat:** If you have 50 routes, `routes/web.php` becomes thousands of lines long and impossible to navigate.
2. **Reusability:** You cannot reuse the logic in that closure anywhere else.
3. **No Dependency Injection:** If the closure needs the `Database` or `Logger`, you have to manually fetch it from the container inside the closure (`Container::getInstance()->make(...)`), which is messy.

---

## 💡 The Solution: Controllers

A Controller is simply a class that groups related request-handling logic together.

Instead of writing the logic in the route file, we point the route to a Controller method:

```php
$router->get('/users', [UserController::class, 'index']);
```

When the Router matches this route, it:
1. Asks the Container to build the `UserController` (which automatically injects any dependencies the controller's constructor needs).
2. Calls the `index` method on that controller.
3. Returns the result as the Response.

---

## 🏗 Implementation

```bash
mkdir -p app/Controllers
touch app/Controllers/HomeController.php
```

### Create a Controller: `app/Controllers/HomeController.php`

```php
<?php

namespace App\Controllers;

use Framework\Http\Request;

class HomeController
{
    // The Container will automatically inject this if we added a Logger binding!
    // public function __construct(Logger $logger) {}

    public function index(Request $request)
    {
        return "Welcome to the Home Page (via Controller)! Method: " . $request->method();
    }
}
```

### Update the Router: `src/Routing/Router.php`

We need to teach the Router how to handle an array like `[HomeController::class, 'index']`. Luckily, we already built the `call()` method in our `Container` (Step 02) which perfectly handles building classes, injecting dependencies, and executing methods.

```php
<?php

namespace Framework\Routing;

use Framework\Container\Container;
use Framework\Http\Request;
use Framework\Http\Response;

class Router
{
    protected array $routes = [];
    protected Container $container;

    // Inject the container so we can build controllers
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function get(string $uri, \Closure|array $action): void
    {
        $this->addRoute('GET', $uri, $action);
    }

    public function post(string $uri, \Closure|array $action): void
    {
        $this->addRoute('POST', $uri, $action);
    }

    protected function addRoute(string $method, string $uri, \Closure|array $action): void
    {
        $this->routes[] = new Route($method, $uri, $action);
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $path = $request->path();

        foreach ($this->routes as $route) {
            if ($route->matches($method, $path)) {
                
                $action = $route->action;

                // Use the Container to execute the action.
                // This works for closures AND [Class, 'method'] arrays!
                // We pass the Request object in the parameters array so the 
                // container can inject it if the controller method asks for it.
                $content = $this->container->call($action, ['request' => $request]);
                
                if ($content instanceof Response) {
                    return $content;
                }
                
                return new Response(is_string($content) ? $content : json_encode($content));
            }
        }

        return new Response('404 Not Found', 404);
    }
}
```

*(Note: Because we added a constructor parameter to `Router`, and we register it via auto-wiring in `AppServiceProvider`, the Container will automatically inject the `$container` into the `Router` when resolving it!)*

### Update: `routes/web.php`

Update your routes file to use the new Controller.

```php
<?php

use Framework\Routing\Router;
use App\Controllers\HomeController;

/** @var Router $router */

$router->get('/', [HomeController::class, 'index']);

$router->get('/about', function () {
    return 'About Us (Still a Closure)';
});
```

---

## ✅ Verify

Run the server:
```bash
php -S 0.0.0.0:8000 -t public
```

Open `http://localhost:8000/`. You should see:
```
Welcome to the Home Page (via Controller)! Method: GET
```

---

## 📌 What We Built

| Element | Purpose |
|---------|---------|
| `Controller` | Organizes application logic into classes. |
| `Router->dispatch()` | Updated to use `Container::call()` to execute actions, enabling automatic dependency injection for route handlers. |

---

## ⚠️ Simplifications vs Laravel

| Laravel | Our Implementation | Reason |
|---------|-------------------|--------|
| Base `Controller` class | Plain PHP classes | Laravel's base controller adds `ValidatesRequests` and `AuthorizesRequests` traits, which we don't have yet. |
| Route Model Binding | Skipped | Requires dynamic route params and DB integration. |
| Controller Middleware | Skipped | Requires parsing annotations/constructors for middleware assignments. |

---

**Next:** [Step 10 — View Engine →](./10-view-engine.md)
