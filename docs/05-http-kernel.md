# Step 05: HTTP Kernel

---

## 🚩 The Problem

Now that we have an `Application` and a `Request`, who is responsible for turning that Request into a Response?

If we put all the logic in `public/index.php`, the file becomes a massive script:
1. Boot the application
2. Load configuration
3. Register service providers
4. Run global middleware
5. Route the request to a controller
6. Handle exceptions

**Why is this bad?**
- `index.php` becomes unmaintainable.
- You can't write tests easily, because testing would require executing `index.php` directly.
- The framework has no clear "engine" that orchestrates the flow.

---

## 💡 The Solution: The Kernel

The **Kernel** (specifically the HTTP Kernel) is the engine of the framework. It acts as the central coordinator. 

You can think of the Kernel as a black box:
**Request goes in $\rightarrow$ Response comes out.**

Internally, the Kernel does three things when `handle()` is called:
1. **Bootstraps** the application (loads config, registers providers).
2. Sends the Request through the **Middleware Pipeline**.
3. Hands the Request to the **Router** to execute your actual code.

By extracting this into a class, `public/index.php` becomes incredibly clean, and the request lifecycle becomes testable.

---

## 🏗 Implementation

### File: `src/Http/Kernel.php`

Create the Kernel class. Right now, it returns a hardcoded Response. In the next steps, we will connect the Router (Step 06) and the Pipeline (Step 07).

```php
<?php

namespace Framework\Http;

use Framework\Foundation\Application;

class Kernel
{
    protected Application $app;

    /**
     * Classes to run before the request is handled.
     * (We will add Config, Providers, etc., here later).
     */
    protected array $bootstrappers = [];

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Handle an incoming HTTP request and return a Response.
     */
    public function handle(Request $request): Response
    {
        try {
            $this->bootstrap();
            
            // For now, return a basic response. 
            // In Step 06/07, this will hand off to the Pipeline/Router.
            return new Response('Kernel is handling the request!', 200);
            
        } catch (\Throwable $e) {
            // In a real framework, this would pass to an Exception Handler
            return new Response('Server Error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Bootstrap the application for HTTP requests.
     */
    protected function bootstrap(): void
    {
        if (! $this->app->hasBeenBootstrapped()) {
            $this->app->bootstrapWith($this->bootstrappers);
        }
    }

    /**
     * Perform any final cleanup after the response has been sent to the browser.
     */
    public function terminate(Request $request, Response $response): void
    {
        // For example: closing database connections, writing session data.
    }
}
```

### Update: `public/index.php`

Now we finalize the Entry Point. It will retrieve the Kernel from the Container, capture the Request, ask the Kernel to handle it, send the Response, and finally terminate.

```php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

// 1. Get the Application instance (configured by the Builder)
$app = require_once __DIR__ . '/../bootstrap/app.php';

// 2. Resolve the Kernel from the Container
$kernel = $app->make(\Framework\Http\Kernel::class);

// 3. Capture the incoming global HTTP state into a Request object
$request = \Framework\Http\Request::capture();

// 4. Pass the Request into the Engine (Kernel) to get a Response
$response = $kernel->handle($request);

// 5. Send the headers and output to the browser
$response->send();

// 6. Perform cleanup
$kernel->terminate($request, $response);
```

---

## ✅ Verify

Run the server:
```bash
php -S 0.0.0.0:8000 -t public
```

Open `http://localhost:8000/`. You should see:
```
Kernel is handling the request!
```

**What just happened?**
1. `index.php` asked the container for `Kernel::class`.
2. The container built it, injecting the `$app`.
3. `Request::capture()` read your HTTP headers.
4. `$kernel->handle()` received the request, ran `bootstrap()`, and returned a Response.
5. `$response->send()` echoed the output.

---

## 📌 What We Built

| Element | Purpose |
|---------|---------|
| `Kernel->handle()` | The main orchestrator method. Takes a Request, returns a Response. |
| `Kernel->bootstrap()` | Ensures necessary application setup runs *before* routing. |
| `public/index.php` | The final, clean state of our Front Controller. |

---

## ⚠️ Simplifications vs Laravel

| Laravel | Our Implementation | Reason |
|---------|-------------------|--------|
| Dispatches Events (`RequestHandled`) | No event system | Kept scope limited to HTTP flow. |
| `sendRequestThroughRouter()` | Simplified | Laravel uses the `Pipeline` class immediately here; we will build that in Step 07. |
| Dedicated `ExceptionHandler` | Inline `try/catch` | We haven't built a robust exception handling system. |

---

**Next:** [Step 06 — Router →](./06-router.md)
