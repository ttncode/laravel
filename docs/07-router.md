# Step 07: Router

---

## 1. 🎯 Purpose (WHY)

The **Router** maps HTTP requests to code. Given a URL like `GET /users/42`, it finds the right handler (closure or controller method) and executes it.

Without a router, you'd write one giant `if/elseif` chain in your entry point — unscalable and unreadable.

**Laravel equivalent:** `Illuminate\Routing\Router` + `Illuminate\Routing\Route`

---

## 2. 🧠 Concept (WHAT)

The Router has two responsibilities:

**1. Registration** — Define which handler responds to which method + URI:
```php
$router->get('/users',       [UserController::class, 'index']);
$router->post('/users',      [UserController::class, 'store']);
$router->get('/users/{id}', [UserController::class, 'show']);
```

**2. Dispatch** — When a request arrives, find the matching route and run it:
```
GET /users/42
  → match against all registered routes
  → found: GET /users/{id} → UserController@show
  → extract parameters: {id: 42}
  → call UserController::show($id = 42)
  → return Response
```

URI matching uses a **regex pattern** converted from `{param}` syntax:
- `/users/{id}` → `/^\/users\/([^\/]+)$/`
- `/posts/{slug}/comments/{id}` → two capture groups

---

## 3. 🏗 Implementation (HOW)

### File: `laravel-clone/src/Routing/Route.php`

```php
<?php

namespace Framework\Routing;

use Framework\Http\Request;

class Route
{
    /**
     * HTTP methods this route responds to (GET, POST, etc.)
     *
     * @var string[]
     */
    protected array $methods;

    /**
     * The URI pattern, e.g. /users/{id}
     */
    protected string $uri;

    /**
     * The action: Closure or [Controller::class, 'method']
     */
    protected mixed $action;

    /**
     * Named parameters extracted from URI, e.g. ['id']
     */
    protected array $paramNames = [];

    /**
     * Middleware for this specific route.
     *
     * @var string[]
     */
    protected array $middleware = [];

    /**
     * Optional route name.
     */
    protected ?string $name = null;

    public function __construct(array $methods, string $uri, mixed $action)
    {
        $this->methods = array_map('strtoupper', $methods);
        $this->uri     = $uri;
        $this->action  = $action;

        $this->compileUri();
    }

    /**
     * Parse {param} placeholders and store param names.
     */
    protected function compileUri(): void
    {
        preg_match_all('/\{(\w+)\}/', $this->uri, $matches);
        $this->paramNames = $matches[1];
    }

    /**
     * Build a regex from the URI pattern.
     * /users/{id} → #^/users/([^/]+)$#
     */
    public function getRegex(): string
    {
        $pattern = preg_replace('/\{(\w+)\}/', '([^/]+)', $this->uri);
        $pattern = str_replace('/', '\/', $pattern);

        return '#^' . $pattern . '$#';
    }

    /**
     * Check if this route matches the given request.
     * Returns extracted parameters, or false if no match.
     */
    public function matches(Request $request): array|false
    {
        if (! in_array($request->method(), $this->methods)) {
            return false;
        }

        if (! preg_match($this->getRegex(), $request->uri(), $matches)) {
            return false;
        }

        // Skip $matches[0] (full match), rest are captured params
        $values = array_slice($matches, 1);

        return array_combine($this->paramNames, $values) ?: [];
    }

    // ─── Fluent API ───────────────────────────────────────────────────────────

    public function middleware(string|array $middleware): static
    {
        $this->middleware = array_merge(
            $this->middleware,
            is_array($middleware) ? $middleware : [$middleware]
        );

        return $this;
    }

    public function name(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    // ─── Getters ──────────────────────────────────────────────────────────────

    public function getAction(): mixed     { return $this->action; }
    public function getMethods(): array    { return $this->methods; }
    public function getUri(): string       { return $this->uri; }
    public function getMiddleware(): array { return $this->middleware; }
    public function getName(): ?string     { return $this->name; }
}
```

### File: `laravel-clone/src/Routing/Router.php`

```php
<?php

namespace Framework\Routing;

use Closure;
use Framework\Container\Container;
use Framework\Http\Request;
use Framework\Http\Response;
use Framework\Pipeline\Pipeline;

class Router
{
    /**
     * @var Route[]
     */
    protected array $routes = [];

    protected Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    // ─── Route Registration ───────────────────────────────────────────────────

    public function get(string $uri, mixed $action): Route
    {
        return $this->addRoute(['GET', 'HEAD'], $uri, $action);
    }

    public function post(string $uri, mixed $action): Route
    {
        return $this->addRoute(['POST'], $uri, $action);
    }

    public function put(string $uri, mixed $action): Route
    {
        return $this->addRoute(['PUT'], $uri, $action);
    }

    public function patch(string $uri, mixed $action): Route
    {
        return $this->addRoute(['PATCH'], $uri, $action);
    }

    public function delete(string $uri, mixed $action): Route
    {
        return $this->addRoute(['DELETE'], $uri, $action);
    }

    public function any(string $uri, mixed $action): Route
    {
        return $this->addRoute(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], $uri, $action);
    }

    protected function addRoute(array $methods, string $uri, mixed $action): Route
    {
        $route = new Route($methods, $uri, $action);
        $this->routes[] = $route;

        return $route;
    }

    // ─── Dispatch ─────────────────────────────────────────────────────────────

    /**
     * Dispatch the request to the matching route.
     * Called by the Kernel's dispatchToRouter() closure.
     */
    public function dispatch(Request $request): Response
    {
        [$route, $params] = $this->findRoute($request);

        // Store matched route in container
        $this->container->instance(Route::class, $route);

        // Run route middleware → then the controller
        return $this->runRouteWithMiddleware($route, $request, $params);
    }

    /**
     * Find a matching route or throw a 404.
     *
     * @return array{Route, array<string, string>}
     */
    protected function findRoute(Request $request): array
    {
        foreach ($this->routes as $route) {
            $params = $route->matches($request);

            if ($params !== false) {
                return [$route, $params];
            }
        }

        throw new \RuntimeException(
            "No route matched [{$request->method()}] {$request->uri()}",
        );
    }

    /**
     * Run the route through its middleware, then call the action.
     */
    protected function runRouteWithMiddleware(Route $route, Request $request, array $params): Response
    {
        $middleware = $route->getMiddleware();

        return (new Pipeline($this->container))
            ->send($request)
            ->through($middleware)
            ->then(function (Request $request) use ($route, $params): Response {
                return $this->callAction($route->getAction(), $request, $params);
            });
    }

    /**
     * Call the route action: Closure or [Controller, 'method'].
     */
    protected function callAction(mixed $action, Request $request, array $params): Response
    {
        if ($action instanceof Closure) {
            $response = $this->container->call($action, $params);
        } elseif (is_array($action)) {
            [$controllerClass, $method] = $action;

            $controller = $this->container->make($controllerClass);
            $response   = $this->container->call([$controller, $method], $params);
        } elseif (is_string($action) && str_contains($action, '@')) {
            [$controllerClass, $method] = explode('@', $action, 2);

            $controller = $this->container->make($controllerClass);
            $response   = $this->container->call([$controller, $method], $params);
        } else {
            throw new \InvalidArgumentException('Invalid route action.');
        }

        return $this->toResponse($response);
    }

    /**
     * Convert various return types to a Response.
     */
    protected function toResponse(mixed $response): Response
    {
        if ($response instanceof Response) {
            return $response;
        }

        if (is_array($response) || is_object($response)) {
            return new Response(
                json_encode($response),
                200,
                ['Content-Type' => 'application/json']
            );
        }

        return new Response((string) $response, 200);
    }
}
```

### File: `laravel-clone/routes/web.php`

```php
<?php

/** @var \Framework\Routing\Router $router */

$router->get('/', function () {
    return '<h1>Hello from Laravel Clone!</h1>';
});

$router->get('/users/{id}', [\App\Controllers\UserController::class, 'show']);

$router->post('/users', [\App\Controllers\UserController::class, 'store']);
```

### Updated: `laravel-clone/src/Routing/RoutingServiceProvider.php`

```php
<?php

namespace Framework\Routing;

use Framework\Support\ServiceProvider;

class RoutingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Router::class, function ($app) {
            return new Router($app);
        });

        $this->app->alias(Router::class, 'router');
    }

    public function boot(): void
    {
        // Load the routes file — safe to do in boot() since Router is registered
        $routesFile = $this->app->routesPath('web.php');

        if (file_exists($routesFile)) {
            $router = $this->app->make(Router::class);
            require $routesFile;
        }
    }
}
```

---

## 4. 🔗 Integration

The full dispatch chain is now complete:

```
Kernel::dispatch($request)
  → Pipeline → middleware
    → Router::dispatch($request)
      → findRoute($request)       ← regex match
      → runRouteWithMiddleware()  ← route-level middleware
        → callAction()            ← Controller or Closure
          → toResponse()          ← wrap return value
      → return Response
```

---

## 5. ✅ Usage Example

```php
// routes/web.php

// Closure route
$router->get('/', function () {
    return 'Welcome!';
});

// Route with parameter
$router->get('/users/{id}', function (string $id) {
    return "User: {$id}";
});

// Controller route
$router->get('/posts/{slug}', [PostController::class, 'show']);

// Route with middleware
$router->get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(\App\Http\Middleware\Authenticate::class);

// Named route
$router->get('/about', function () {
    return 'About page';
})->name('about');
```

---

## 6. 📌 Key Elements

| Element | Purpose |
|---------|---------|
| `Route::compileUri()` | Extracts `{param}` names |
| `Route::getRegex()` | Converts URI pattern to regex |
| `Route::matches()` | Checks method + URI, returns params |
| `Router::addRoute()` | Registers a route |
| `Router::dispatch()` | Entry point from Kernel |
| `Router::findRoute()` | Iterates routes to find match |
| `Router::callAction()` | Executes Closure or Controller method |
| `Router::toResponse()` | Wraps return value in Response |
| `routes/web.php` | User-defined routes |

---

## 7. ⚠️ Simplifications Made

| Laravel | Our Clone | Reason |
|---------|-----------|--------|
| `Router.php` has 1530 lines | ~150 lines | Removed: named routes URL generation, resource routes, redirect routes, view routes |
| `RouteCollection` is a separate class | Routes stored as `array` in Router | Sufficient for learning |
| Route groups with prefix/namespace/middleware | Not implemented | Complex feature — add later |
| `Route::model()` implicit binding | Skipped | Eloquent dependency |
| Regex constraints (`where()`) | Not implemented | Add if needed |
| `Route::fallback()` | Skipped | Can be added trivially |
| Method override via `_method` field | Not implemented | Add in a middleware if needed |

---

**Next:** [Step 08 — Controller Dispatch →](./08-controller.md)
