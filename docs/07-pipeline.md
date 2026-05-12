# Step 07: Middleware Pipeline

---

## 🚩 The Problem

Before a Request reaches the Router (and your application logic), it often needs to pass through several checks:
- Is the user authenticated?
- Are they within their rate limit?
- Do we need to add CORS headers to the response?
- Does the request need to be logged?

If we write this logic inside the Kernel or Router, those classes become bloated with unrelated concerns. 

```php
// Bad: Kernel handling unrelated logic
if (! $request->header('Authorization')) {
    return new Response('Unauthorized', 401);
}

// ... handle request ...

$response->header('Access-Control-Allow-Origin', '*');
```

**Why is this bad?**
1. **Violation of Open-Closed Principle:** To add a new check, you must modify the core framework code.
2. **Order Matters:** Some things must run *before* the request (Auth), and some must run *after* the response is generated (CORS headers, Response Logging). 
3. **Rigid:** You can't easily turn features on and off for specific routes.

---

## 💡 The Solution: The Pipeline (Onion Architecture)

Instead of hardcoding checks, we wrap the application in layers (like an onion). 

1. The Request enters the outermost layer (e.g., Logger).
2. The Logger passes the Request to the next layer (e.g., Auth).
3. Auth checks the Request. If it passes, it hands it to the core (the Router).
4. The Router generates a Response and hands it *back out* to Auth.
5. Auth hands the Response *back out* to the Logger.
6. The Logger returns the Response to the browser.

```
Request → [ Log ] → [ Auth ] → [ Router ] → Response
             ↑          ↑           |
Response ← [ Log ] ← [ Auth ] ← ────────┘
```

This is called the **Middleware Pipeline**. Each layer is a "Middleware".
A middleware looks like this:

```php
public function handle($request, $next)
{
    // Do something BEFORE
    
    $response = $next($request); // Pass deeper into the onion
    
    // Do something AFTER
    
    return $response;
}
```

---

## 🏗 Implementation

```bash
mkdir -p src/Pipeline
touch src/Pipeline/Pipeline.php
```

### File: `src/Pipeline/Pipeline.php`

The Pipeline class is responsible for taking an array of Middleware, folding them together into a single callable "onion", and passing the Request through it.

```php
<?php

namespace Framework\Pipeline;

use Framework\Container\Container;

class Pipeline
{
    protected Container $container;
    protected mixed $passable;
    protected array $pipes = [];

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Set the object being sent through the pipeline (the Request).
     */
    public function send(mixed $passable): static
    {
        $this->passable = $passable;
        return $this;
    }

    /**
     * Set the array of middleware classes.
     */
    public function through(array $pipes): static
    {
        $this->pipes = $pipes;
        return $this;
    }

    /**
     * Run the pipeline with a final destination callback (the Router).
     */
    public function then(\Closure $destination): mixed
    {
        // array_reduce builds the onion from the inside out.
        // We reverse the pipes so the first middleware in the array
        // ends up being the outermost layer of the onion.
        $pipeline = array_reduce(
            array_reverse($this->pipes),
            $this->carry(),
            $destination
        );

        return $pipeline($this->passable);
    }

    /**
     * Get a Closure that represents a single layer of the onion.
     */
    protected function carry(): \Closure
    {
        return function ($stack, $pipe) {
            return function ($passable) use ($stack, $pipe) {
                // If the pipe is a string (class name), resolve it from container
                if (is_string($pipe)) {
                    $pipe = $this->container->make($pipe);
                }

                // Call the handle() method on the middleware, passing the 
                // $passable (Request) and the $stack (the next layer inward)
                return $pipe->handle($passable, $stack);
            };
        };
    }
}
```

### Create a Test Middleware

Let's create a simple middleware to prove the pipeline works.

```bash
mkdir -p app/Http/Middleware
touch app/Http/Middleware/ExampleMiddleware.php
```

```php
<?php

namespace App\Http\Middleware;

use Framework\Http\Request;
use Framework\Http\Response;

class ExampleMiddleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        // 1. BEFORE action (e.g., logging)
        error_log("Middleware: Request received for " . $request->path());

        // 2. Pass request to the next layer (eventually the Router)
        /** @var Response $response */
        $response = $next($request);

        // 3. AFTER action (e.g., adding headers)
        // Note: Our basic Response class doesn't have a robust header API yet, 
        // so we'll just modify the content for demonstration.
        $content = $response->getContent() ?? '';
        $response->setContent($content . ' [Passed through Middleware]');

        return $response;
    }
}
```
*(Note: You will need to add `public function getContent(): mixed { return $this->content; }` to `Framework\Http\Response` for the above to work perfectly).*

### Update: `src/Http/Kernel.php`

Now, update the Kernel to use the Pipeline instead of calling the Router directly. It will fetch the global middleware array that was configured in Step 03 via `ApplicationBuilder`.

```php
<?php

namespace Framework\Http;

use Framework\Foundation\Application;
use Framework\Routing\Router;
use Framework\Pipeline\Pipeline;
use Framework\Foundation\Configuration\Middleware;

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
            
            // Fetch global middleware from the Application config
            $middlewareConfig = $this->app->make(Middleware::class);
            $globalMiddleware = $middlewareConfig->getGlobalMiddleware();

            // Run the Request through the Pipeline
            return (new Pipeline($this->app))
                ->send($request)
                ->through($globalMiddleware)
                ->then($this->dispatchToRouter());
            
        } catch (\Throwable $e) {
            return new Response('Server Error: ' . $e->getMessage(), 500);
        }
    }

    protected function dispatchToRouter(): \Closure
    {
        return function ($request) {
            $routingConfig = $this->app->make('config.routing');
            if (isset($routingConfig['web']) && file_exists($routingConfig['web'])) {
                $router = $this->router;
                require_once $routingConfig['web'];
            }
            return $this->router->dispatch($request);
        };
    }

    // ... bootstrap() and terminate() remain the same
}
```

### Update: `bootstrap/app.php`

Finally, register your test middleware using the ApplicationBuilder.

```php
<?php

use Framework\Foundation\Application;
use Framework\Foundation\Configuration\Exceptions;
use Framework\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php'
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\ExampleMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
```

---

## ✅ Verify

Run the server:
```bash
php -S 0.0.0.0:8000 -t public
```

Open `http://localhost:8000/`. You should see:
```
Welcome to the Home Page! [Passed through Middleware]
```

Check your terminal output. You should see the error log from the "BEFORE" phase:
```
Middleware: Request received for /
```

The Pipeline successfully wrapped the Route execution!

---

## 📌 What We Built

| Element | Purpose |
|---------|---------|
| `Pipeline` | Folds an array of classes into nested closures (`array_reduce`). |
| `Middleware` | An interceptor that wraps the core application logic. |
| `Kernel->handle()` | Refactored to pass the Request through the Pipeline before the Router. |

---

## ⚠️ Simplifications vs Laravel

| Laravel | Our Implementation | Reason |
|---------|-------------------|--------|
| Middleware Parameters (`auth:api`) | Basic class names | Parsing parameters adds string manipulation complexity. |
| Route-specific Middleware | Global only | We kept the Router simple (Step 06). Adding route middleware requires Pipeline execution inside the Router. |
| Exception Catching inside Pipeline | Skipped | Laravel's pipeline catches exceptions at every layer. |

---

**Next:** [Step 08 — Service Providers →](./08-service-providers.md)
