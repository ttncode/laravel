# Step 08: Service Providers

---

## 🚩 The Problem

As our framework grows, various components need initialization during boot:
- The Router needs to load `routes/web.php`.
- A Database connection needs to read credentials from config.
- A View Engine needs to know where template files are stored.

If we put all this setup code in the `Kernel` or `Application` class, those classes will become massive and tightly coupled to every feature in the application.

```php
// Bad: Kernel knows too much about specific services
protected function bootstrap() {
    // Setup Router
    require 'routes/web.php';
    
    // Setup Database
    $this->app->singleton('db', function() { ... });
    
    // Setup Views
    $this->app->singleton('view', function() { ... });
}
```

**Why is this bad?**
1. **Monolithic:** The core framework becomes impossible to maintain or extend.
2. **Third-party packages:** How does a community package (like a PDF generator or a Payment gateway) register its own bindings without modifying your core `Kernel` code?

---

## 💡 The Solution: Service Providers

A **Service Provider** is the central place to configure and bootstrap a specific piece of the application.

Instead of one giant setup function, we divide setup into modular classes. A Service Provider has two phases:
1. `register()`: Bind things into the IoC Container. (Do NOT use other services here, because they might not be registered yet).
2. `boot()`: Run setup code that requires other services (like loading routes, or attaching event listeners).

The Application will collect all providers, run *every* `register()` method, and then run *every* `boot()` method.

---

## 🏗 Implementation

```bash
mkdir -p src/Support
touch src/Support/ServiceProvider.php
mkdir -p app/Providers
touch app/Providers/AppServiceProvider.php
```

### File: `src/Support/ServiceProvider.php`
The abstract base class for all providers.

```php
<?php

namespace Framework\Support;

use Framework\Foundation\Application;

abstract class ServiceProvider
{
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Register any application services into the container.
     */
    public function register(): void
    {
        // Default empty implementation
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Default empty implementation
    }
}
```

### Create a Provider: `app/Providers/AppServiceProvider.php`
This is an application-level provider where the user can bind their own services. We will also use it to handle routing, replacing the hardcoded logic in the Kernel.

```php
<?php

namespace App\Providers;

use Framework\Support\ServiceProvider;
use Framework\Routing\Router;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Example: $this->app->singleton(MyService::class);
    }

    public function boot(): void
    {
        $this->bootRoutes();
    }

    /**
     * Load the routing files configured in bootstrap/app.php
     */
    protected function bootRoutes(): void
    {
        $routingConfig = $this->app->make('config.routing');
        
        if (isset($routingConfig['web']) && file_exists($routingConfig['web'])) {
            $router = $this->app->make(Router::class);
            require $routingConfig['web'];
        }
    }
}
```

### Clean up the Kernel

Now we can remove the hardcoded routing logic from the Kernel. We also need to add a "Bootstrapper" that tells the Application to register and boot the providers.

Update `src/Http/Kernel.php`:

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

    // Array of bootstrapper classes to run before handling a request
    protected array $bootstrappers = [
        \Framework\Foundation\Bootstrap\RegisterProviders::class,
        \Framework\Foundation\Bootstrap\BootProviders::class,
    ];

    public function __construct(Application $app, Router $router)
    {
        $this->app = $app;
        $this->router = $router;
    }

    public function handle(Request $request): Response
    {
        try {
            $this->bootstrap();
            
            $middlewareConfig = $this->app->make(Middleware::class);
            $globalMiddleware = $middlewareConfig->getGlobalMiddleware();

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
            // The Router has already been populated with routes
            // by the AppServiceProvider's boot() method!
            return $this->router->dispatch($request);
        };
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

### Create the Bootstrappers

We need to create the two classes referenced in the Kernel above.

```bash
mkdir -p src/Foundation/Bootstrap
touch src/Foundation/Bootstrap/RegisterProviders.php
touch src/Foundation/Bootstrap/BootProviders.php
```

**`src/Foundation/Bootstrap/RegisterProviders.php`**:
```php
<?php

namespace Framework\Foundation\Bootstrap;

use Framework\Foundation\Application;

class RegisterProviders
{
    public function bootstrap(Application $app): void
    {
        // In Laravel, this array is built dynamically from config/app.php
        // and composer.json package discovery. For simplicity, we hardcode it.
        $providers = [
            \App\Providers\AppServiceProvider::class,
        ];

        foreach ($providers as $providerClass) {
            $provider = new $providerClass($app);
            $provider->register();
            
            // Store it in the app so we can boot it later
            // Note: We need to add a register() method to Application.php
            $app->registerProvider($provider);
        }
    }
}
```

**`src/Foundation/Bootstrap/BootProviders.php`**:
```php
<?php

namespace Framework\Foundation\Bootstrap;

use Framework\Foundation\Application;

class BootProviders
{
    public function bootstrap(Application $app): void
    {
        // This delegates to the boot() method we wrote in Step 03
        $app->boot();
    }
}
```

### Update `Application.php`

We need to add the `registerProvider` method we used above.

Add this method to `src/Foundation/Application.php`:

```php
    public function registerProvider(\Framework\Support\ServiceProvider $provider): void
    {
        $this->serviceProviders[] = $provider;
    }
```

---

## ✅ Verify

Run the server:
```bash
php -S 0.0.0.0:8000 -t public
```

Open `http://localhost:8000/`. You should still see your Home Page message.

**What changed?**
The functionality is exactly the same, but the architecture is vastly superior. The `Kernel` no longer knows anything about `routes/web.php`. The routing configuration is now encapsulated entirely inside `AppServiceProvider`.

---

## 📌 What We Built

| Element | Purpose |
|---------|---------|
| `ServiceProvider` | Base class for modular bootstrapping. |
| `AppServiceProvider` | Connects the user's route files to the Router. |
| `Bootstrappers` | Classes run by the Kernel to prepare the Application state. |

---

## ⚠️ Simplifications vs Laravel

| Laravel | Our Implementation | Reason |
|---------|-------------------|--------|
| `config/app.php` Providers Array | Hardcoded in `RegisterProviders` | We haven't built the Config system yet (Step 11). |
| Deferred Providers | Skipped | Lazy-loading optimization, not core architecture. |
| Package Auto-Discovery | Skipped | Requires parsing `vendor/composer/installed.json`. |

---

**Next:** [Step 09 — Controllers →](./09-controller.md)
