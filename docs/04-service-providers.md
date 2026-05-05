# Step 04: Service Providers

---

## 1. 🎯 Purpose (WHY)

**Service Providers** are the central place to register services into the container. They answer: *"When should this service be registered, and how?"*

Without them, `bootstrap/app.php` would grow to thousands of lines — every binding, every route, every config would be registered in one place.

Service Providers give you:
- **Organization** — each feature (routing, DB, mail) has its own provider
- **Lifecycle control** — `register()` runs first, `boot()` runs after all are registered
- **Deferred loading** — providers can be lazy (not implemented here, but the pattern exists)

**Laravel equivalent:** `Illuminate\Support\ServiceProvider`

---

## 2. 🧠 Concept (WHAT)

Every provider has two methods:

```
register()  → Bind things into the container. No accessing other services yet.
boot()      → Run startup logic. All services are guaranteed to be registered now.
```

The order matters:
```
1. ALL providers call register()  ← Just binding, no side effects
2. ALL providers call boot()      ← Now safe to use other services
```

This two-phase pattern prevents ordering bugs: if Provider A calls Provider B's service during `register()`, B might not have registered it yet. The `boot()` phase guarantees everything is available.

---

## 3. 🏗 Implementation (HOW)

### File: `laravel-clone/src/Support/ServiceProvider.php`

```php
<?php

namespace Framework\Support;

use Framework\Foundation\Application;

abstract class ServiceProvider
{
    /**
     * The application instance.
     */
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Register bindings into the container.
     * DO NOT use other services here — they may not be registered yet.
     */
    public function register(): void
    {
        // Override in subclass
    }

    /**
     * Boot after all providers have been registered.
     * Safe to use any registered service here.
     */
    public function boot(): void
    {
        // Override in subclass
    }
}
```

### File: `laravel-clone/app/Providers/AppServiceProvider.php`

```php
<?php

namespace App\Providers;

use Framework\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Example: bind a config value
        // $this->app->singleton('timezone', fn () => 'UTC');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Example: run startup logic after everything is registered
    }
}
```

### File: `laravel-clone/src/Routing/RoutingServiceProvider.php`

```php
<?php

namespace Framework\Routing;

use Framework\Support\ServiceProvider;

class RoutingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind the Router as a singleton
        $this->app->singleton(Router::class, function ($app) {
            return new Router($app);
        });

        // Register 'router' alias
        $this->app->alias(Router::class, 'router');
    }
}
```

### Updated: `laravel-clone/bootstrap/app.php`

```php
<?php

$app = new \Framework\Foundation\Application(
    basePath: dirname(__DIR__)
);

// Bind the HTTP Kernel
$app->singleton(
    \Framework\Http\Kernel::class,
    \Framework\Http\Kernel::class
);

// Register core providers
$app->register(new \Framework\Routing\RoutingServiceProvider($app));
$app->register(new \App\Providers\AppServiceProvider($app));

return $app;
```

### Updated: `laravel-clone/src/Foundation/Application.php`

The `register()` method was already added in Step 2. But we need to finalize how the app boots providers when `boot()` is called from the Kernel.

The `boot()` method in Application calls `bootProvider()` on each registered provider:

```php
// Already in Application from Step 2:

public function register(\Framework\Support\ServiceProvider $provider): \Framework\Support\ServiceProvider
{
    $provider->register();
    $this->serviceProviders[get_class($provider)] = $provider;

    if ($this->booted) {
        $this->bootProvider($provider);
    }

    return $provider;
}

public function boot(): void
{
    if ($this->booted) {
        return;
    }

    foreach ($this->bootingCallbacks as $callback) {
        $callback($this);
    }

    foreach ($this->serviceProviders as $provider) {
        $this->bootProvider($provider);
    }

    $this->booted = true;

    foreach ($this->bootedCallbacks as $callback) {
        $callback($this);
    }
}

protected function bootProvider(\Framework\Support\ServiceProvider $provider): void
{
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
}
```

---

## 4. 🔗 Integration

The provider lifecycle is triggered by the **Kernel** (Step 5):

```
Kernel::handle($request)
  → Kernel::bootstrap()
    → app()->bootstrapWith([
        RegisterProviders::class,   ← calls register() on all providers
        BootProviders::class,       ← calls boot() on all providers
      ])
```

For now (before the Kernel is built), providers are registered manually in `bootstrap/app.php`.

---

## 5. ✅ Usage Example

### Creating a custom provider

```php
// app/Providers/DatabaseServiceProvider.php
namespace App\Providers;

use Framework\Support\ServiceProvider;
use PDO;

class DatabaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('db', function ($app) {
            // Config not available yet during register() in a real app,
            // but for simplicity we hardcode here
            return new PDO('sqlite:' . $app->storagePath('app.sqlite'));
        });
    }

    public function boot(): void
    {
        // Safe to use 'db' here — it's registered now
        $db = $this->app->make('db');
        // Run any startup queries, set PDO attributes, etc.
    }
}
```

### Register it in `bootstrap/app.php`

```php
$app->register(new \App\Providers\DatabaseServiceProvider($app));
```

### Use in a controller

```php
class UserController
{
    public function __construct(private \PDO $db) {}
    // Container resolves 'db' automatically via type-hinting
    // (only if bound with the PDO class name, not string 'db')
}

// Or resolve manually:
$db = app()->make('db');
```

---

## 6. 📌 Key Elements

| Element | Purpose |
|---------|---------|
| `register()` | Bind services — runs first, no side effects |
| `boot()` | Run startup logic — all services available |
| Two-phase lifecycle | Prevents "service not yet registered" bugs |
| `$this->app` | Access to the container |
| `RoutingServiceProvider` | Registers the Router singleton |
| `AppServiceProvider` | User's main app-level provider |

---

## 7. ⚠️ Simplifications Made

| Laravel | Our Clone | Reason |
|---------|-----------|--------|
| `ServiceProvider` has 663 lines | ~35 lines | Removed: `publishes()`, `loadRoutesFrom()`, `loadViewsFrom()`, `mergeConfigFrom()`, Artisan commands |
| Providers loaded from `bootstrap/providers.php` | Manually in `bootstrap/app.php` | No file-based autodiscovery |
| Deferred providers (`DeferrableProvider`) | Skipped | Lazy loading optimization |
| `callAfterResolving()` helper | Skipped | Advanced hook system |
| `booting()` / `booted()` callbacks per provider | Only global callbacks in Application | Simpler lifecycle |

---

**Next:** [Step 05 — HTTP Kernel →](./05-http-kernel.md)
