# Step 02: Application Bootstrap

---

## 1. 🎯 Purpose (WHY)

The **Application** is the central object of the entire framework. It:
- Is the IoC container (holds all bindings)
- Manages the application lifecycle (boot, bootstrap)
- Registers base services (routing, events, etc.)
- Provides path helpers

Without it, there's no central registry — every class would have to create its own dependencies.

**Laravel equivalent:** `Illuminate\Foundation\Application`

---

## 2. 🧠 Concept (WHAT)

The Application **IS** the container. In Laravel, `Application extends Container`. This is intentional:

```
Application = Container + Lifecycle management + Path resolution
```

Key lifecycle phases:
1. **Construction** — Register base bindings
2. **Bootstrap** — Load env, config, providers (happens per-request)
3. **Boot** — Call `boot()` on all providers
4. **Handle** — Process the request
5. **Terminate** — Post-response cleanup

---

## 3. 🏗 Implementation (HOW)

### File: `laravel-clone/src/Foundation/Application.php`

```php
<?php

namespace Framework\Foundation;

use Framework\Container\Container;

class Application extends Container
{
    /**
     * The base path for the framework installation.
     */
    protected string $basePath;

    /**
     * Indicates if the application has been bootstrapped.
     */
    protected bool $hasBeenBootstrapped = false;

    /**
     * Indicates if the application has been booted.
     */
    protected bool $booted = false;

    /**
     * All registered service providers.
     *
     * @var array<string, \Framework\Support\ServiceProvider>
     */
    protected array $serviceProviders = [];

    /**
     * Callbacks to run before booting.
     */
    protected array $bootingCallbacks = [];

    /**
     * Callbacks to run after booting.
     */
    protected array $bootedCallbacks = [];

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');

        $this->registerBaseBindings();
    }

    /**
     * Register the core bindings into the container.
     * The app binds itself — so `$app->make('app')` returns $app.
     */
    protected function registerBaseBindings(): void
    {
        // Make this instance globally available
        static::setInstance($this);

        // Bind 'app' key to this instance
        $this->instance('app', $this);

        // Bind the class name too
        $this->instance(Application::class, $this);
        $this->instance(Container::class, $this);
    }

    /**
     * Run the bootstrappers for the application.
     * Called once per request by the Kernel.
     */
    public function bootstrapWith(array $bootstrappers): void
    {
        $this->hasBeenBootstrapped = true;

        foreach ($bootstrappers as $bootstrapper) {
            $this->make($bootstrapper)->bootstrap($this);
        }
    }

    /**
     * Register a service provider with the application.
     */
    public function register(\Framework\Support\ServiceProvider $provider): \Framework\Support\ServiceProvider
    {
        $provider->register();

        $this->serviceProviders[get_class($provider)] = $provider;

        // If the app is already booted, boot this provider immediately
        if ($this->booted) {
            $this->bootProvider($provider);
        }

        return $provider;
    }

    /**
     * Boot all registered service providers.
     * Called after all providers have been registered.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        // Fire booting callbacks
        foreach ($this->bootingCallbacks as $callback) {
            $callback($this);
        }

        // Boot each provider
        foreach ($this->serviceProviders as $provider) {
            $this->bootProvider($provider);
        }

        $this->booted = true;

        // Fire booted callbacks
        foreach ($this->bootedCallbacks as $callback) {
            $callback($this);
        }
    }

    /**
     * Boot a single service provider.
     */
    protected function bootProvider(\Framework\Support\ServiceProvider $provider): void
    {
        if (method_exists($provider, 'boot')) {
            $provider->boot();
        }
    }

    /**
     * Register a callback to run before boot.
     */
    public function booting(callable $callback): void
    {
        $this->bootingCallbacks[] = $callback;
    }

    /**
     * Register a callback to run after boot.
     */
    public function booted(callable $callback): void
    {
        $this->bootedCallbacks[] = $callback;
    }

    public function hasBeenBootstrapped(): bool
    {
        return $this->hasBeenBootstrapped;
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    // ─── Path Helpers ────────────────────────────────────────────────────────

    public function basePath(string $path = ''): string
    {
        return $this->basePath . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }

    public function appPath(string $path = ''): string
    {
        return $this->basePath('app' . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : ''));
    }

    public function configPath(string $path = ''): string
    {
        return $this->basePath('config' . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : ''));
    }

    public function resourcePath(string $path = ''): string
    {
        return $this->basePath('resources' . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : ''));
    }

    public function storagePath(string $path = ''): string
    {
        return $this->basePath('storage' . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : ''));
    }

    public function publicPath(string $path = ''): string
    {
        return $this->basePath('public' . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : ''));
    }

    public function routesPath(string $path = ''): string
    {
        return $this->basePath('routes' . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : ''));
    }
}
```

### Updated: `laravel-clone/bootstrap/app.php`

```php
<?php

$app = new \Framework\Foundation\Application(
    basePath: dirname(__DIR__)
);

// Bind the Kernel
$app->singleton(
    \Framework\Http\Kernel::class,
    \Framework\Http\Kernel::class
);

return $app;
```

---

## 4. 🔗 Integration

`Application` extends `Container` (Step 3). When you call:
```php
$app->make(\Framework\Http\Kernel::class)
```

That's the Container resolving the Kernel. The Application is both the app AND the IoC container — same as Laravel.

**Dependency chain so far:**
```
public/index.php
  → bootstrap/app.php
    → new Application(basePath)
      → registerBaseBindings()
        → Container::instance('app', $this)
```

---

## 5. ✅ Usage Example

```php
// In bootstrap/app.php or anywhere after boot:
$app = app(); // returns the Application instance

// Path helpers
$app->basePath();            // /path/to/laravel-clone
$app->configPath('app.php'); // /path/to/laravel-clone/config/app.php

// Register a service
$app->singleton('db', function () {
    return new PDO('sqlite::memory:');
});

// Resolve a service
$db = $app->make('db');
```

---

## 6. 📌 Key Elements

| Element | Purpose |
|---------|---------|
| `extends Container` | Application IS the IoC container |
| `registerBaseBindings()` | Binds `app` key to itself |
| `bootstrapWith()` | Runs bootstrapper classes (load config, env) |
| `register()` | Registers a service provider |
| `boot()` | Calls `boot()` on all registered providers |
| Path helpers | `basePath()`, `configPath()`, etc. |

---

## 7. ⚠️ Simplifications Made

| Laravel | Our Clone | Reason |
|---------|-----------|--------|
| `Application` has 1736 lines | ~150 lines | Removed caching, Cloud, Package Manifest |
| Registers EventServiceProvider, LogServiceProvider, RoutingServiceProvider by default | We do it manually in `bootstrap/app.php` | Explicit is better for learning |
| Path resolution handles `.laravel/` vs `bootstrap/` | Just uses `basePath()` | Simpler |
| Has `deferredServices` for lazy loading providers | Skipped | Optimization, not core concept |
| `singleton(Mix::class)` and `PackageManifest` | Skipped | Build tools, not architecture |

---

**Next:** [Step 03 — IoC Container →](./03-container.md)
