# Step 06: Middleware Pipeline

---

## 1. 🎯 Purpose (WHY)

The **Pipeline** solves the "middleware onion" problem: how do you wrap a request through multiple layers of processing — each layer able to act before AND after the inner layers — without messy nesting?

Without a pipeline you'd write:
```php
$response = $auth->handle($request, function ($req) use ($logging) {
    return $logging->handle($req, function ($req) {
        return $router->dispatch($req); // buried deep
    });
});
```

The Pipeline makes this declarative and extendable:
```php
(new Pipeline)
    ->send($request)
    ->through([$auth, $logging])
    ->then(fn ($req) => $router->dispatch($req));
```

**Laravel equivalent:** `Illuminate\Pipeline\Pipeline` (326 lines → ~130 lines)

---

## 2. 🧠 Concept (WHAT)

The pipeline uses `array_reduce` to build a **nested closure chain** — an onion:

```
$destination = fn($req) => $router->dispatch($req)

// array_reduce builds (innermost first):
$layer3 = fn($req) => $loggingMiddleware->handle($req, $destination)
$layer2 = fn($req) => $authMiddleware->handle($req, $layer3)
$layer1 = fn($req) => $csrfMiddleware->handle($req, $layer2)

// Calling $layer1($request) unwinds the whole onion
```

Each middleware receives `($request, $next)` where `$next` is the next layer (or the final destination). Calling `$next($request)` passes control inward.

```php
class AuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasHeader('Authorization')) {
            return new Response('Unauthorized', 401);
        }

        $response = $next($request);   // ← goes deeper

        // Post-processing happens here, after inner layers run
        return $response;
    }
}
```

---

## 3. 🏗 Implementation (HOW)

### File: `laravel-clone/src/Pipeline/Pipeline.php`

```php
<?php

namespace Framework\Pipeline;

use Closure;
use Framework\Container\Container;
use Framework\Http\Request;
use Throwable;

class Pipeline
{
    /**
     * The container to resolve middleware classes.
     */
    protected ?Container $container;

    /**
     * The object traveling through the pipeline (the request).
     */
    protected mixed $passable;

    /**
     * The middleware classes to pipe through.
     *
     * @var array<string|object>
     */
    protected array $pipes = [];

    /**
     * The method to call on each middleware class.
     */
    protected string $method = 'handle';

    public function __construct(?Container $container = null)
    {
        $this->container = $container;
    }

    /**
     * Set the object to send through the pipeline.
     */
    public function send(mixed $passable): static
    {
        $this->passable = $passable;

        return $this;
    }

    /**
     * Set the middleware to pipe through.
     *
     * @param array<string|object|Closure> $pipes
     */
    public function through(array $pipes): static
    {
        $this->pipes = $pipes;

        return $this;
    }

    /**
     * Run the pipeline with a final destination callback.
     *
     * Uses array_reduce to build the nested closure chain, then
     * invokes it with the passable.
     */
    public function then(Closure $destination): mixed
    {
        // Build the closure chain from inside out
        // array_reverse so the first pipe in the list runs first
        $pipeline = array_reduce(
            array_reverse($this->pipes),
            $this->carry(),               // the reducer
            $this->prepareDestination($destination) // the innermost layer
        );

        return $pipeline($this->passable);
    }

    /**
     * Wrap the final destination so exceptions propagate cleanly.
     */
    protected function prepareDestination(Closure $destination): Closure
    {
        return function (mixed $passable) use ($destination): mixed {
            return $destination($passable);
        };
    }

    /**
     * Returns the reducer function used by array_reduce.
     *
     * Each call wraps the current $stack in a new closure that
     * resolves and calls the $pipe first.
     */
    protected function carry(): Closure
    {
        return function (Closure $stack, mixed $pipe): Closure {
            return function (mixed $passable) use ($stack, $pipe): mixed {
                // Case 1: pipe is already a Closure
                if ($pipe instanceof Closure) {
                    return $pipe($passable, $stack);
                }

                // Case 2: pipe is a class name string — resolve from container
                if (is_string($pipe)) {
                    $instance = $this->container
                        ? $this->container->make($pipe)
                        : new $pipe;

                    return $instance->{$this->method}($passable, $stack);
                }

                // Case 3: pipe is already an object
                if (is_object($pipe)) {
                    return $pipe->{$this->method}($passable, $stack);
                }

                throw new \InvalidArgumentException(
                    'Invalid pipe — must be a Closure, class name string, or object.'
                );
            };
        };
    }
}
```

### File: `laravel-clone/src/Http/Middleware/MiddlewareInterface.php`

```php
<?php

namespace Framework\Http\Middleware;

use Framework\Http\Request;
use Framework\Http\Response;
use Closure;

interface MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response;
}
```

---

## 4. 🔗 Integration

The Kernel (Step 5) already uses the Pipeline:

```php
// In Kernel::sendRequestThroughRouter()
return (new Pipeline($this->app))
    ->send($request)
    ->through($this->middleware)   // array of class names
    ->then($this->dispatchToRouter());
```

The `dispatchToRouter()` closure is the **final destination** — it's the innermost layer of the onion. The router (Step 7) sits at the center.

---

## 5. ✅ Usage Example

### A complete middleware example

```php
// app/Http/Middleware/AddJsonHeader.php
namespace App\Http\Middleware;

use Framework\Http\Request;
use Framework\Http\Response;
use Closure;

class AddJsonHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        // PRE: run before the inner layers
        // (nothing to do before for this middleware)

        $response = $next($request); // ← pass inward

        // POST: modify the response coming back out
        $response->setHeader('Content-Type', 'application/json');

        return $response;
    }
}
```

### Using the pipeline standalone

```php
$result = (new \Framework\Pipeline\Pipeline())
    ->send('hello')
    ->through([
        function ($value, $next) {
            return $next(strtoupper($value)); // transform
        },
        function ($value, $next) {
            $response = $next($value);
            return $response . '!'; // append after
        },
    ])
    ->then(function ($value) {
        return $value; // destination
    });

// $result = 'HELLO!'
```

### Route-level middleware

In a future step (Step 7 — Router), routes can have their own middleware:

```php
Route::get('/admin', [AdminController::class, 'index'])
    ->middleware(\App\Http\Middleware\Authenticate::class);

// The Kernel runs global middleware first,
// then route-specific middleware wraps around the controller
```

---

## 6. 📌 Key Elements

| Element | Purpose |
|---------|---------|
| `send()` | Sets the passable (request) |
| `through()` | Sets the middleware array |
| `then()` | Runs the pipeline with a final destination |
| `carry()` | Reducer that builds the nested closure chain |
| `prepareDestination()` | Wraps the final callback |
| `array_reduce(array_reverse(...))` | Core trick — builds onion from inside out |
| `$method = 'handle'` | Convention: all middleware have `handle()` |

---

## 7. ⚠️ Simplifications Made

| Laravel | Our Clone | Reason |
|---------|-----------|--------|
| `Pipeline.php` has 326 lines | ~130 lines | Removed: `via()`, `pipe()`, `thenReturn()`, `finally()`, transaction wrapping |
| Pipe string parsing (`Class:param1,param2`) | Not implemented | Middleware params are uncommon in basic apps |
| `via()` to change method name | Not implemented | Always `handle()` |
| Exception wrapping in `handleException()` | Bare `throw` | Simpler |
| `withinTransaction()` | Skipped | DB transaction wrapping is advanced |
| Container used for all pipes | Optional — falls back to `new $pipe` | More resilient without container |

---

**Next:** [Step 07 — Router →](./07-router.md)
