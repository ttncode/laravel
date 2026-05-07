# Step 03: Application

---

## 🚩 The Problem

After Step 02, we have a generic IoC container. It resolves dependencies. It caches singletons. But it knows nothing about the framework it lives in:

```php
$container = new Container();

// Where are config files? The container doesn't know.
// Where are views? The container doesn't know.
// When should services boot? The container doesn't know.
// How do you run setup code once before handling requests? The container doesn't know.
```

A generic container is like an empty filing cabinet. Useful — but it doesn't know it's a *web framework's* filing cabinet. It has no concept of:

- **Base paths** — where is the application installed? Where are its config, views, and routes?
- **Lifecycle** — what runs before the first request? What happens when all providers are registered?
- **Identity** — code throughout the app needs to reach the container. How?

---

## 🔍 Why a Separate Application Class Is Needed

**Option 1: Use a global variable**

```php
global $container; // in every file that needs it
```

Terrible. Global state is impossible to test and creates hidden coupling.

**Option 2: Pass the container everywhere**

```php
function handleRequest(Container $c, Request $req) { ... }
```

Cleaner, but the container ends up as a parameter of everything — it becomes noise.

**Option 3: Static singleton**

```php
Container::getInstance()->make(...)
```

Better — the container already supports this (Step 02). But `Container::getInstance()` is a framework-agnostic generic. The framework itself should have a named, opinionated hub with path awareness and a lifecycle.

**The right approach: extend the container**

The `Application` *is* a container — it extends it. It adds:
1. Framework-specific singleton registration (it registers itself)
2. Path resolution helpers (`basePath()`, `configPath()`, etc.)
3. A lifecycle (`bootstrapWith()`, `boot()`) that the Kernel will drive

This is exactly what Laravel does: `Application extends Container`.

---

## 💡 The Solution: Application as the Framework's Central Hub

The Application object is the single, central object that:
- **IS** the container (inherits all binding/resolution methods)
- **Knows the filesystem** (where config lives, where views live, etc.)
- **Manages the lifecycle** (bootstrapping, booting)
- **Is globally accessible** (via `Container::getInstance()`)

```
public/index.php
    ↓
$app = new Application(basePath: __DIR__ . '/..')
    ↓
$app->make(Kernel::class)   ← uses inherited Container::make()
$app->basePath('config')    ← uses Application-specific path helper
```

Everything else in the framework receives `$app` (as `Application` or `Container`) and asks it for what it needs.

---

## 🏗 Implementation

```bash
mkdir -p src/Foundation
touch src/Foundation/Application.php
touch bootstrap/app.php
```

### File: `src/Foundation/Application.php`

```php
<?php

namespace Framework\Foundation;

use Framework\Container\Container;

class Application extends Container
{
    /**
     * The resolved base path for this installation.
     */
    protected string $basePath;

    /**
     * Whether bootstrapWith() has been called.
     * The Kernel calls it once and checks this flag to avoid re-running.
     */
    protected bool $hasBeenBootstrapped = false;

    /**
     * Whether boot() has completed.
     */
    protected bool $booted = false;

    /**
     * Registered service providers (added in Step 08).
     *
     * @var array<string, \Framework\Support\ServiceProvider>
     */
    protected array $serviceProviders = [];

    /**
     * Callbacks to fire just before boot().
     */
    protected array $bootingCallbacks = [];

    /**
     * Callbacks to fire just after boot().
     */
    protected array $bootedCallbacks = [];

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');

        $this->registerBaseBindings();
    }

    /**
     * Bind the application into the container as both 'app' and its own class names.
     *
     * This is how code anywhere in the framework calls `$app->make('app')`
     * or `$app->make(Application::class)` and gets this exact object back.
     */
    protected function registerBaseBindings(): void
    {
        // Register this instance as the global singleton
        static::setInstance($this);

        // Bind under 'app' key (short alias)
        $this->instance('app', $this);

        // Bind under full class names
        $this->instance(Application::class, $this);
        $this->instance(Container::class, $this);
    }

    // ─── Lifecycle ────────────────────────────────────────────────────────────

    /**
     * Run a list of bootstrapper classes in order.
     *
     * Called by the Kernel once per process. Each bootstrapper receives $this
     * (the application) and runs its setup — loading config, registering
     * providers, booting them, etc.
     *
     * This will be populated with real bootstrappers in Step 05 (Kernel)
     * and Step 11 (Config).
     *
     * @param class-string[] $bootstrappers
     */
    public function bootstrapWith(array $bootstrappers): void
    {
        $this->hasBeenBootstrapped = true;

        foreach ($bootstrappers as $bootstrapper) {
            $this->make($bootstrapper)->bootstrap($this);
        }
    }

    /**
     * Boot all registered service providers.
     *
     * Called after all providers have been registered. Safe to use any
     * registered service inside a provider's boot() method.
     *
     * Service providers are introduced in Step 08. Until then, this method
     * does nothing harmful — it just iterates an empty array.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ($this->bootingCallbacks as $callback) {
            $callback($this);
        }

        foreach ($this->serviceProviders as $provider) {
            if (method_exists($provider, 'boot')) {
                $provider->boot();
            }
        }

        $this->booted = true;

        foreach ($this->bootedCallbacks as $callback) {
            $callback($this);
        }
    }

    /**
     * Register a callback to run just before boot().
     */
    public function booting(callable $callback): void
    {
        $this->bootingCallbacks[] = $callback;
    }

    /**
     * Register a callback to run just after boot().
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

    // ─── Path Helpers ─────────────────────────────────────────────────────────

    /**
     * Resolve a path relative to the application's base directory.
     *
     * $app->basePath()            → /var/www/laravel-clone
     * $app->basePath('config')    → /var/www/laravel-clone/config
     */
    public function basePath(string $path = ''): string
    {
        return $this->joinPath($this->basePath, $path);
    }

    public function appPath(string $path = ''): string
    {
        return $this->joinPath($this->basePath . '/app', $path);
    }

    public function configPath(string $path = ''): string
    {
        return $this->joinPath($this->basePath . '/config', $path);
    }

    public function publicPath(string $path = ''): string
    {
        return $this->joinPath($this->basePath . '/public', $path);
    }

    public function resourcePath(string $path = ''): string
    {
        return $this->joinPath($this->basePath . '/resources', $path);
    }

    public function routesPath(string $path = ''): string
    {
        return $this->joinPath($this->basePath . '/routes', $path);
    }

    public function storagePath(string $path = ''): string
    {
        return $this->joinPath($this->basePath . '/storage', $path);
    }

    protected function joinPath(string $base, string $path): string
    {
        return $path ? $base . DIRECTORY_SEPARATOR . ltrim($path, '/\\') : $base;
    }
}
```

### File: `bootstrap/app.php`

This file is the **application factory**. `public/index.php` calls it once per request to get the `$app` instance. Its only job right now: create the Application and register the HTTP Kernel so the container can build it.

```php
<?php

$app = new \Framework\Foundation\Application(
    basePath: dirname(__DIR__)
);

// Tell the container how to build the Kernel.
// We're using the class name as both key and concrete so the
// container can auto-resolve it via ReflectionClass.
// (The Kernel class is created in Step 05.)
$app->singleton(
    \Framework\Http\Kernel::class,
    \Framework\Http\Kernel::class
);

return $app;
```

### Update `public/index.php`

Add the Application to the entry point so we can verify it works. The Kernel lines are left as comments — they are enabled in Step 05.

```php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';

// Verify: show the Application's base path
echo 'Application booted. Base path: ' . $app->basePath();

// These lines are completed in Step 05:
// $kernel  = $app->make(\Framework\Http\Kernel::class);
// $request = \Framework\Http\Request::capture();
// $response = $kernel->handle($request);
// $response->send();
// $kernel->terminate($request, $response);
```

---

## ✅ Verify

```bash
php -S 0.0.0.0:8000 -t public
```

Open `http://localhost:8000/`. You should see:

```
Application booted. Base path: /home/you/laravel-clone
```

The base path is the `laravel-clone/` directory — one level above `public/`.

Try the path helpers in a quick test:

```bash
php -r "
require 'vendor/autoload.php';
\$app = require 'bootstrap/app.php';
echo \$app->configPath() . PHP_EOL;
echo \$app->resourcePath('views') . PHP_EOL;
echo \$app->storagePath('logs/app.log') . PHP_EOL;
"
```

Expected output:
```
/home/you/laravel-clone/config
/home/you/laravel-clone/resources/views
/home/you/laravel-clone/storage/logs/app.log
```

---

## 📌 What We Built

| Element | Purpose |
|---------|---------|
| `Application extends Container` | App IS the container — inherits all binding/resolution |
| `registerBaseBindings()` | Binds `$this` under `'app'` and class names |
| `bootstrapWith()` | Runs ordered bootstrapper classes (used by Kernel in Step 05) |
| `boot()` | Calls `boot()` on all service providers (used in Step 08) |
| `basePath()` etc. | Filesystem path resolution helpers |
| `bootstrap/app.php` | Application factory — called from `public/index.php` |

**Directory structure after this step:**

```
laravel-clone/
├── bootstrap/
│   └── app.php              ← NEW
├── composer.json
├── composer.lock
├── public/
│   └── index.php            ← UPDATED
├── src/
│   ├── Container/
│   │   └── Container.php
│   └── Foundation/
│       └── Application.php  ← NEW
└── vendor/
```

---

## ⚠️ Simplifications vs Laravel

| Laravel | Our Implementation | Reason |
|---------|-------------------|--------|
| `Application` is ~1,700 lines | ~150 lines | Removed: deferred providers, package manifest, Cloud integrations, locale/environment helpers |
| Providers loaded from `bootstrap/providers.php` | Manually registered in `bootstrap/app.php` until Step 08 | File-based autodiscovery is a convenience feature |
| Has `deferrable` provider support | Not implemented | Lazy loading optimization — not core concept |
| `detect()` environment from hostname | Not implemented | Rarely used; env() from .env covers this (Step 11) |
| `registerCoreContainerAliases()` registers 30+ aliases | We register only what we build | No facades, no static proxies |

---

**Next:** [Step 04 — Request & Response →](./04-request-response.md)
