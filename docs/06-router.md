# Step 06: Router

---

## 🚩 The Problem

Currently, our Kernel returns `"Kernel is handling the request!"` for *every* URL. 

To handle different URLs, a naive approach inside the Kernel might look like this:

```php
$path = $request->path();

if ($path === '/') {
    return new Response('Home Page');
} elseif ($path === '/about') {
    return new Response('About Us');
} else {
    return new Response('404 Not Found', 404);
}
```

**Why is this bad?**
1. **Unmaintainable:** An app with 100 routes becomes a massive `if/else` block.
2. **Dynamic URLs are hard:** How do you match `/users/42` without writing complex regex manually?
3. **HTTP Methods:** How do you separate `GET /users` (list users) from `POST /users` (create user)?
4. **Violates Single Responsibility:** The Kernel should orchestrate the flow, not determine page contents.

---

## 💡 The Solution: A Router Component

The Router is an internal registry. It maintains a list of "Rules" (Routes).

1. **Registration Phase:** During boot, we tell the router what URLs exist.
   `$router->get('/about', function () { ... });`
2. **Matching Phase:** When a Request arrives, the Router compares the Request's URI and Method against its registry.
3. **Execution Phase:** If it finds a match, it executes the associated function (or Controller). If not, it throws a 404 Exception.

---

## 🏗 Implementation

```bash
mkdir -p src/Routing
touch src/Routing/Router.php
touch src/Routing/Route.php
mkdir routes
touch routes/web.php
```

### File: `src/Routing/Route.php`
A simple DTO to hold a registered route's information.

```php
<?php

namespace Framework\Routing;

class Route
{
    public function __construct(
        public string $method,
        public string $uri,
        public \Closure|array $action
    ) {}
    
    /**
     * Check if a given path matches this route's URI.
     * (Simplified: Exact match only. Real Laravel uses Regex for dynamic params).
     */
    public function matches(string $method, string $path): bool
    {
        return $this->method === $method && $this->uri === $path;
    }
}
```

### File: `src/Routing/Router.php`
The registry that holds routes and attempts to dispatch the Request to one of them.

```php
<?php

namespace Framework\Routing;

use Framework\Http\Request;
use Framework\Http\Response;

class Router
{
    /** @var Route[] */
    protected array $routes = [];

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

    /**
     * Find a matching route for the Request and execute it.
     */
    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $path = $request->path();

        foreach ($this->routes as $route) {
            if ($route->matches($method, $path)) {
                
                // Execute the route action
                $content = call_user_func($route->action);
                
                // Ensure we always return a Response object
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

### File: `routes/web.php`
This is where the application developer defines the routes.

```php
<?php

use Framework\Routing\Router;

/** @var Router $router */

$router->get('/', function () {
    return 'Welcome to the Home Page!';
});

$router->get('/about', function () {
    return 'About Us';
});
```

### Update: `src/Http/Kernel.php`
We need to connect the Router to the Kernel. For now, we will manually load `routes/web.php` inside the Kernel (in Step 08, a Service Provider will take over this job, reading from the `ApplicationBuilder` configuration).

```php
<?php

namespace Framework\Http;

use Framework\Foundation\Application;
use Framework\Routing\Router;

class Kernel
{
    protected Application $app;
    protected Router $router;
    protected array $bootstrappers = [];

    public function __construct(Application $app, Router $router)
    {
        $this->app = $app;
        $this->router = $router;
    }

    public function handle(Request $request): Response
    {
        try {
            $this->bootstrap();
            
            // Temporary: Load the routes manually here.
            // We use the application's config routing paths set in Step 03
            $routingConfig = $this->app->make('config.routing');
            if (isset($routingConfig['web']) && file_exists($routingConfig['web'])) {
                $router = $this->router;
                require $routingConfig['web'];
            }

            // Ask the router to match the request and execute the logic
            return $this->router->dispatch($request);
            
        } catch (\Throwable $e) {
            return new Response('Server Error: ' . $e->getMessage(), 500);
        }
    }

    protected function bootstrap(): void
    {
        if (! $this->app->hasBeenBootstrapped()) {
            $this->app->bootstrapWith($this->bootstrappers);
        }
    }

    public function terminate(Request $request, Response $response): void {}
}
```

---

## ✅ Verify

Run the server:
```bash
php -S 0.0.0.0:8000 -t public
```

Test 1 — `http://localhost:8000/`
```
Welcome to the Home Page!
```

Test 2 — `http://localhost:8000/about`
```
About Us
```

Test 3 — `http://localhost:8000/unknown`
```
404 Not Found
```

---

## 📌 What We Built

| Element | Purpose |
|---------|---------|
| `Route` | A simple data object containing Method, URI, and Action. |
| `Router` | A registry that holds `Route` objects and dispatches Requests to them. |
| `routes/web.php` | The developer-facing file to register application routes. |

---

## ⚠️ Simplifications vs Laravel

| Laravel | Our Implementation | Reason |
|---------|-------------------|--------|
| Dynamic Route Params (`/users/{id}`) | Exact string matching only | Regex parsing adds significant complexity. |
| Route Groups & Prefixes | Skipped | Requires maintaining stack state inside the Router. |
| Dependency Injection in Actions | Basic `call_user_func` | We will add Container injection for Controllers in Step 09. |
| `RouteCollection` class | Array of routes | Simpler data structure. |

---

**Next:** [Step 07 — Middleware Pipeline →](./07-pipeline.md)
