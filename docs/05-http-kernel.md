# Step 05: HTTP Kernel

---

## 1. 🎯 Purpose (WHY)

The **HTTP Kernel** is the gatekeeper between the raw HTTP request and the framework. It:
- Bootstraps the application (loads config, env, providers)
- Passes the request through the **middleware stack** (pipeline)
- Dispatches to the **router**
- Returns a response

Think of it as the orchestrator. It doesn't do any of these things itself — it delegates to the Pipeline (Step 6) and Router (Step 7).

**Laravel equivalent:** `Illuminate\Foundation\Http\Kernel` (~702 lines → ~120 lines)

---

## 2. 🧠 Concept (WHAT)

The Kernel's `handle()` method is the critical path:

```
handle($request)
  → bootstrap()              ← Load env, config, providers
  → (new Pipeline)           ← Push request through middleware
      ->send($request)
      ->through($middleware)
      ->then(dispatchToRouter)
  → return $response
```

The clever part: **the router is the final destination of the pipeline**. Middleware wraps around it like an onion — each layer can inspect/modify the request before and after.

---

## 3. 🏗 Implementation (HOW)

### File: `laravel-clone/src/Http/Kernel.php`

```php
<?php

namespace Framework\Http;

use Framework\Foundation\Application;
use Framework\Pipeline\Pipeline;
use Framework\Routing\Router;
use Throwable;

class Kernel
{
    /**
     * The application instance.
     */
    protected Application $app;

    /**
     * The router instance.
     */
    protected Router $router;

    /**
     * The global middleware stack — runs on every request.
     *
     * @var class-string[]
     */
    protected array $middleware = [];

    /**
     * The bootstrappers run before handling a request.
     *
     * @var class-string[]
     */
    protected array $bootstrappers = [
        \Framework\Foundation\Bootstrap\LoadConfiguration::class,
        \Framework\Foundation\Bootstrap\RegisterProviders::class,
        \Framework\Foundation\Bootstrap\BootProviders::class,
    ];

    public function __construct(Application $app, Router $router)
    {
        $this->app    = $app;
        $this->router = $router;
    }

    /**
     * Handle an incoming HTTP request.
     * This is called from public/index.php.
     */
    public function handle(Request $request): Response
    {
        try {
            $response = $this->sendRequestThroughRouter($request);
        } catch (Throwable $e) {
            $response = $this->renderException($request, $e);
        }

        return $response;
    }

    /**
     * Push the request through middleware, then dispatch to the router.
     */
    protected function sendRequestThroughRouter(Request $request): Response
    {
        // Store request in the container
        $this->app->instance('request', $request);

        // Run bootstrappers once per process
        $this->bootstrap();

        // Pipeline: middleware onion → router at the center
        return (new Pipeline($this->app))
            ->send($request)
            ->through($this->middleware)
            ->then($this->dispatchToRouter());
    }

    /**
     * Bootstrap the application (once per process).
     */
    public function bootstrap(): void
    {
        if (! $this->app->hasBeenBootstrapped()) {
            $this->app->bootstrapWith($this->bootstrappers);
        }
    }

    /**
     * The final destination of the pipeline — dispatch to the router.
     */
    protected function dispatchToRouter(): \Closure
    {
        return function (Request $request): Response {
            $this->app->instance('request', $request);

            return $this->router->dispatch($request);
        };
    }

    /**
     * Render an exception into an HTTP response.
     */
    protected function renderException(Request $request, Throwable $e): Response
    {
        $status  = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
        $message = $this->app->make('config')->get('app.debug', false)
            ? $e->getMessage() . "\n" . $e->getTraceAsString()
            : 'Server Error';

        return new Response($message, $status);
    }

    /**
     * Perform post-response cleanup.
     * Called from public/index.php after send().
     */
    public function terminate(Request $request, Response $response): void
    {
        // Call terminate() on any middleware that implements it
        foreach ($this->middleware as $middlewareClass) {
            $instance = $this->app->make($middlewareClass);

            if (method_exists($instance, 'terminate')) {
                $instance->terminate($request, $response);
            }
        }
    }
}
```

### File: `laravel-clone/src/Foundation/Bootstrap/LoadConfiguration.php`

```php
<?php

namespace Framework\Foundation\Bootstrap;

use Framework\Config\Repository;
use Framework\Foundation\Application;

class LoadConfiguration
{
    public function bootstrap(Application $app): void
    {
        // Load all PHP files from config/ directory
        $config = new Repository();

        foreach (glob($app->configPath('*.php')) as $file) {
            $key = basename($file, '.php');
            $config->set($key, require $file);
        }

        $app->instance('config', $config);
        $app->instance(Repository::class, $config);
    }
}
```

### File: `laravel-clone/src/Foundation/Bootstrap/RegisterProviders.php`

```php
<?php

namespace Framework\Foundation\Bootstrap;

use Framework\Foundation\Application;

class RegisterProviders
{
    public function bootstrap(Application $app): void
    {
        // Providers are already registered in bootstrap/app.php for now.
        // In a full framework, this would read from config/app.php 'providers' array.

        $config = $app->make('config');
        $providers = $config->get('app.providers', []);

        foreach ($providers as $providerClass) {
            $app->register(new $providerClass($app));
        }
    }
}
```

### File: `laravel-clone/src/Foundation/Bootstrap/BootProviders.php`

```php
<?php

namespace Framework\Foundation\Bootstrap;

use Framework\Foundation\Application;

class BootProviders
{
    public function bootstrap(Application $app): void
    {
        $app->boot();
    }
}
```

### File: `laravel-clone/config/app.php`

```php
<?php

return [
    'name'  => 'Laravel Clone',
    'env'   => 'local',
    'debug' => true,

    'providers' => [
        \Framework\Routing\RoutingServiceProvider::class,
        \App\Providers\AppServiceProvider::class,
        // Add more providers here
    ],
];
```

---

## 4. 🔗 Integration

The full bootstrap sequence is now:

```
public/index.php
  → $app = require bootstrap/app.php       (Application created)
  → $kernel = $app->make(Kernel::class)    (Kernel auto-resolved)
  → $request = Request::capture()
  → $response = $kernel->handle($request)
       → bootstrap()
           → LoadConfiguration::bootstrap() → binds 'config'
           → RegisterProviders::bootstrap() → reads config/app.php providers
           → BootProviders::bootstrap()     → calls boot() on all providers
       → Pipeline → middleware → Router
  → $response->send()
  → $kernel->terminate($request, $response)
```

---

## 5. ✅ Usage Example

### Adding a global middleware

```php
// app/Http/Middleware/LogRequests.php
namespace App\Http\Middleware;

use Framework\Http\Request;
use Framework\Http\Response;

class LogRequests
{
    public function handle(Request $request, \Closure $next): Response
    {
        error_log('Request: ' . $request->method() . ' ' . $request->uri());

        $response = $next($request);

        error_log('Response: ' . $response->getStatusCode());

        return $response;
    }
}
```

```php
// In your Kernel subclass (or directly in bootstrap):
// app/Http/Kernel.php
namespace App\Http;

class Kernel extends \Framework\Http\Kernel
{
    protected array $middleware = [
        \App\Http\Middleware\LogRequests::class,
    ];
}
```

---

## 6. 📌 Key Elements

| Element | Purpose |
|---------|---------|
| `handle()` | Main entry point — orchestrates everything |
| `bootstrap()` | Loads config, registers + boots providers (once) |
| `sendRequestThroughRouter()` | Pipeline setup |
| `dispatchToRouter()` | Closure passed as pipeline destination |
| `terminate()` | Post-response cleanup |
| `$bootstrappers` | Ordered list of bootstrapper classes |
| `$middleware` | Global middleware applied to every request |

---

## 7. ⚠️ Simplifications Made

| Laravel | Our Clone | Reason |
|---------|-----------|--------|
| `Kernel.php` has 702 lines | ~120 lines | Removed: middleware priority sorting, `withoutMiddleware`, request duration tracking |
| Has `HandleExceptions` bootstrapper | Skipped | Add PHP `set_exception_handler` manually if needed |
| Has `RegisterFacades` bootstrapper | Skipped | No facades in our framework |
| Global vs route middleware distinction | Only global middleware | Route middleware added in Step 6 |
| Maintenance mode check | Skipped | Not architectural |
| Request lifecycle duration handlers | Skipped | Monitoring/APM concern |

---

**Next:** [Step 06 — Middleware Pipeline →](./06-middleware-pipeline.md)
