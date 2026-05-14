# Step 03: Application & Builder

---

## 🚩 The Problem

After Step 02, we have a generic IoC container. It resolves dependencies and caches singletons. However, it knows nothing about the framework:

1. **Path Awareness**: Where are config files? Where are routes?
2. **Configuration**: How do developers register middleware, routing, and exception handlers without diving deep into framework internals?
3. **Lifecycle**: What coordinates the startup sequence?

In early framework designs (like Laravel 10 and older), developers manually registered the HTTP Kernel, Console Kernel, and Exception Handler directly into the container inside `bootstrap/app.php`. While explicit, this exposed too much internal wiring to the everyday developer.

---

## 🔍 Why the Builder Pattern?

**The Old Way (Pre-Laravel 11)**
```php
$app = new Application($_ENV['APP_BASE_PATH'] ?? dirname(__DIR__));
$app->singleton(Kernel::class, Kernel::class);
$app->singleton(ExceptionHandler::class, Handler::class);
return $app;
```
This forces the developer to understand IoC bindings just to start the app. 

**The Modern Way (Laravel 11+)**
To provide a pristine developer experience, Laravel hides the core wiring behind an **Application Builder**. The developer defines *what* they want (routes, middleware), and the Builder handles the *how* (IoC bindings, array merging) when `.create()` is called.

---

## 💡 The Solution: Application + ApplicationBuilder

1. **`Application`**: Extends the Container. It is the central hub holding paths and the configured routing/middleware callbacks.
2. **`ApplicationBuilder`**: A fluent interface (`->withRouting()`, `->withMiddleware()`) that gathers configuration, binds the core components (like the Kernel), and returns the configured `Application`.
3. **Configuration Objects**: Simple DTOs (`Middleware`, `Exceptions`) passed to the closures so developers get IDE autocomplete when configuring the app.

---

### File: `src/Foundation/Configuration/Middleware.php`
A simple object to collect middleware configuration.

```php
<?php

namespace Framework\Foundation\Configuration;

class Middleware
{
    /** @var array<class-string> */
    protected array $globalMiddleware = [];

    /**
     * Append a global middleware to the stack.
     */
    public function append(string $middleware): static
    {
        $this->globalMiddleware[] = $middleware;
        return $this;
    }

    public function getGlobalMiddleware(): array
    {
        return $this->globalMiddleware;
    }
}
```

### File: `src/Foundation/Configuration/Exceptions.php`
A placeholder object for exception handling configuration.

```php
<?php

namespace Framework\Foundation\Configuration;

class Exceptions
{
    // In a full framework, this would allow registering custom error renderers.
}
```

### File: `src/Http/Request.php`
A simple DTO to represent the incoming HTTP request.

```php
<?php

namespace Framework\Http;

class Request
{
    /**
     * Create a new Request instance from the current globals.
     */
    public static function capture(): static
    {
        return new static();
    }
}
```

### File: `src/Http/Response.php`
A simple object to encapsulate the HTTP response sent back to the browser.

```php
<?php

namespace Framework\Http;

class Response
{
    public function __construct(
        protected string $content = '',
        protected int $status = 200,
        protected array $headers = []
    ) {}

    public function send(): void
    {
        echo $this->content;
    }

    public function getContent(): string
    {
        return $this->content;
    }
}
```

### File: `src/Http/Kernel.php`
The Kernel is the orchestrator of the HTTP request. At this stage, we only need a skeleton so the container has something to bind to. (We will implement the logic in Step 05).

```php
<?php

namespace Framework\Http;

use Framework\Foundation\Application;

class Kernel
{
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(Request $request): Response
    {
        return new Response('Kernel is handling the request!');
    }
}
```

### File: `src/Foundation/Configuration/ApplicationBuilder.php`
The fluent builder that constructs the Application. Note the `withKernels()` method which explicitly handles the core framework bindings.

```php
<?php

namespace Framework\Foundation\Configuration;

use Framework\Foundation\Application;

class ApplicationBuilder
{
    protected Application $app;
    protected array $routing = [];
    protected ?\Closure $middlewareCallback = null;
    protected ?\Closure $exceptionsCallback = null;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Register the standard kernel classes for the application.
     */
    public function withKernels(): static
    {
        $this->app->singleton(
            \Framework\Http\Kernel::class,
            \Framework\Http\Kernel::class
        );

        return $this;
    }

    public function withRouting(?string $web = null, ?string $api = null): static
    {
        if ($web) $this->routing['web'] = $web;
        if ($api) $this->routing['api'] = $api;
        
        return $this;
    }

    public function withMiddleware(callable $callback): static
    {
        $this->middlewareCallback = $callback;
        return $this;
    }

    public function withExceptions(callable $callback): static
    {
        $this->exceptionsCallback = $callback;
        return $this;
    }

    /**
     * Finalize the application configuration and bind core components.
     */
    public function create(): Application
    {
        // 1. Store the routing paths in the application
        $this->app->instance('config.routing', $this->routing);

        // 2. Process Middleware Configuration
        $middleware = new Middleware();
        if ($this->middlewareCallback) {
            call_user_func($this->middlewareCallback, $middleware);
        }
        $this->app->instance(Middleware::class, $middleware);

        // 3. Process Exceptions Configuration
        $exceptions = new Exceptions();
        if ($this->exceptionsCallback) {
            call_user_func($this->exceptionsCallback, $exceptions);
        }
        $this->app->instance(Exceptions::class, $exceptions);

        return $this->app;
    }
}
```

### File: `src/Foundation/Application.php`
The core Application class. We update `configure()` to automatically invoke `withKernels()` so the developer doesn't have to call it manually in `bootstrap/app.php`.

```php
<?php

namespace Framework\Foundation;

use Framework\Container\Container;
use Framework\Foundation\Configuration\ApplicationBuilder;

class Application extends Container
{
    protected string $basePath;
    protected bool $hasBeenBootstrapped = false;
    protected bool $booted = false;
    protected array $serviceProviders = [];

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
        $this->registerBaseBindings();
    }

    /**
     * The modern Laravel entry point. Returns the Builder.
     */
    public static function configure(string $basePath): ApplicationBuilder
    {
        return (new ApplicationBuilder(new static($basePath)))
            ->withKernels(); // Automatically register the Kernel
    }

    protected function registerBaseBindings(): void
    {
        static::setInstance($this);
        $this->instance('app', $this);
        $this->instance(Application::class, $this);
        $this->instance(Container::class, $this);
    }

    public function bootstrapWith(array $bootstrappers): void
    {
        $this->hasBeenBootstrapped = true;
        foreach ($bootstrappers as $bootstrapper) {
            $this->make($bootstrapper)->bootstrap($this);
        }
    }

    public function hasBeenBootstrapped(): bool
    {
        return $this->hasBeenBootstrapped;
    }

    public function boot(): void
    {
        if ($this->booted) return;

        foreach ($this->serviceProviders as $provider) {
            if (method_exists($provider, 'boot')) $provider->boot();
        }
        $this->booted = true;
    }

    // Path Helpers
    public function basePath(string $path = ''): string { return $this->joinPath($this->basePath, $path); }
    public function configPath(string $path = ''): string { return $this->joinPath($this->basePath . '/config', $path); }
    public function routesPath(string $path = ''): string { return $this->joinPath($this->basePath . '/routes', $path); }
    public function resourcePath(string $path = ''): string { return $this->joinPath($this->basePath . '/resources', $path); }

    protected function joinPath(string $base, string $path): string {
        return $path ? $base . DIRECTORY_SEPARATOR . ltrim($path, '/\\') : $base;
    }
}
```

### File: `bootstrap/app.php`
Now, our bootstrap file perfectly mimics Laravel 13's fluent configuration style.

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
        // We will append middleware here later
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
```

---

## ✅ Verify

(No actual code execution needed right now as you requested to update docs only, but if you were to run it, `public/index.php` remains unchanged and will successfully boot the Application via the new builder).

---

## 📌 What We Built

| Element | Purpose |
|---------|---------|
| `Application::configure()` | Static factory that returns the Builder |
| `ApplicationBuilder` | Fluent interface to collect settings and bind core services |
| `Middleware` / `Exceptions` | Configuration objects passed to builder callbacks |
| `bootstrap/app.php` | The developer-facing configuration file |

---

## ⚠️ Simplifications vs Laravel

| Laravel | Our Implementation | Reason |
|---------|-------------------|--------|
| `ApplicationBuilder` binds Providers, Console Kernel, Routing | Only binds HTTP Kernel & stores config | Keep it focused on HTTP lifecycle |
| `Middleware` object configures aliases, groups, priorities | Only manages a flat list of global middleware | Simplicity |

---

**Next:** [Step 04 — Request & Response →](./04-request-response.md)
