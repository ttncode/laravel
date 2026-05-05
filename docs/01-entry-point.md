# Step 01: Entry Point

---

## 1. 🎯 Purpose (WHY)

Every web request starts at one file — the **entry point**. This is the single "front controller" that:
- Loads the autoloader
- Boots the application
- Handles the HTTP request
- Sends back a response

Without this, PHP would serve files directly with no framework overhead.

**Laravel equivalent:** `public/index.php`

---

## 2. 🧠 Concept (WHAT)

The front controller pattern: **one PHP file handles all HTTP requests**.

Your web server (Nginx/Apache) is configured to route every request to `public/index.php`. From there, the framework takes over.

```
Browser → Nginx → public/index.php → Framework → Response → Browser
```

In Laravel 13, `public/index.php` does exactly 4 things:
1. Loads Composer's autoloader
2. Requires `bootstrap/app.php` to get the Application instance
3. Creates the HTTP Kernel
4. Handles the request and sends the response

---

## 3. 🏗 Implementation (HOW)

### File: `laravel-clone/composer.json`

```json
{
    "name": "laravel-clone/framework",
    "description": "A minimal Laravel-inspired PHP framework for learning",
    "autoload": {
        "psr-4": {
            "App\\": "app/",
            "Framework\\": "src/"
        }
    },
    "require": {
        "php": "^8.2"
    }
}
```

### File: `laravel-clone/public/index.php`

```php
<?php

// 1. Load Composer's autoloader
// This registers PSR-4 class loading for our namespaces
require_once __DIR__ . '/../vendor/autoload.php';

// 2. Boot the application
// bootstrap/app.php returns the Application instance
$app = require_once __DIR__ . '/../bootstrap/app.php';

// 3. Create the HTTP Kernel
// The Kernel knows how to handle HTTP requests
$kernel = $app->make(\Framework\Http\Kernel::class);

// 4. Handle the request → get a response
$request  = \Framework\Http\Request::capture();
$response = $kernel->handle($request);

// 5. Send the response to the browser
$response->send();

// 6. Kernel cleanup (terminate any middleware)
$kernel->terminate($request, $response);
```

### File: `laravel-clone/bootstrap/app.php`

```php
<?php

// Create and configure the Application
$app = new \Framework\Foundation\Application(
    basePath: dirname(__DIR__)
);

// Register the HTTP Kernel binding
$app->singleton(
    \Framework\Http\Kernel::class,
    \Framework\Http\Kernel::class
);

return $app;
```

---

## 4. 🔗 Integration

This is the **first step**, so there's nothing to integrate yet. The files created here will be the foundation everything else connects to:

- `bootstrap/app.php` will grow as we add providers (Step 4)
- `public/index.php` stays nearly identical — it's intentionally thin

---

## 5. ✅ Usage Example

After completing all steps, visiting `http://localhost/` will:
1. Nginx routes to `public/index.php`
2. Autoloader loads all classes
3. Application boots
4. Kernel handles request
5. Router matches URL to a controller
6. Controller returns a response
7. Response is sent to browser

For now, run:
```bash
cd laravel-clone
composer install
php -S localhost:8000 -t public
```

---

## 6. 📌 Key Elements

| Element | Purpose |
|---------|---------|
| `public/index.php` | Single entry point (front controller) |
| `bootstrap/app.php` | Application factory |
| `composer.json` | PSR-4 autoloading setup |
| `require_once vendor/autoload.php` | Class loading |

---

## 7. ⚠️ Simplifications Made

| Laravel | Our Clone | Reason |
|---------|-----------|--------|
| `public/index.php` uses `$app->handleRequest()` | We call `$kernel->handle()` directly | More explicit |
| Laravel uses `Illuminate\Http\Request::capture()` | Same pattern, our own class | Identical concept |
| Laravel has maintenance mode check | Skipped | Not core |
| Laravel registers a request started timestamp | Skipped | Not architectural |

---

**Next:** [Step 02 — Application Bootstrap →](./02-application-bootstrap.md)
