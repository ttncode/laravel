# Step 11: Config & Env

---

## 🚩 The Problem

Imagine setting up a database connection in a Service Provider:

```php
$db = new Database(
    host: '127.0.0.1', 
    user: 'root', 
    password: 'supersecretpassword'
);
```

**Why is this bad?**
1. **Security Risk:** If you commit this code to GitHub, your password is public.
2. **Environment Rigidity:** When you deploy to production, the database host will change. You would have to modify the code itself to deploy, meaning you can't use the exact same codebase locally and in production.
3. **Scattered Magic Strings:** Configuration values (like "pagination_limit = 15") get scattered throughout the codebase.

---

## 💡 The Solution: `.env` and Config Files

We separate configuration into two layers:

1. **Environment Variables (`.env`)**: A file that is strictly *ignored* by Git. It contains secrets and environment-specific values (like DB passwords or API keys). 
2. **Configuration Files (`config/*.php`)**: Files committed to version control that return arrays. They use `env()` to read from the environment, falling back to safe defaults.

To make accessing these values easy across the framework, we build a **Config Repository** — a central registry loaded during Application Bootstrap.

---

## 🏗 Implementation

```bash
mkdir -p src/Config
touch src/Config/Repository.php
touch .env
mkdir config
touch config/app.php
```

### Update `src/helpers.php`

Add the `env()` helper and `config()` helper.

```php
// ... existing view() helper ...

if (! function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }
        return $value;
    }
}

if (! function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return \Framework\Container\Container::getInstance()
            ->make(\Framework\Config\Repository::class)
            ->get($key, $default);
    }
}
```

### File: `src/Config/Repository.php`

A simple wrapper around an array to hold our loaded configuration.

```php
<?php

namespace Framework\Config;

class Repository
{
    protected array $items = [];

    public function set(string $key, mixed $value): void
    {
        $this->items[$key] = $value;
    }

    /**
     * Get a config value.
     * Supports dot notation: config('app.timezone')
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $array = $this->items;

        foreach ($segments as $segment) {
            if (isset($array[$segment])) {
                $array = $array[$segment];
            } else {
                return $default;
            }
        }

        return $array;
    }
}
```

### Create a Bootstrapper: `LoadConfiguration.php`

We need to load `.env` variables and PHP config arrays into the Repository before the application handles the request.

```bash
touch src/Foundation/Bootstrap/LoadConfiguration.php
```

```php
<?php

namespace Framework\Foundation\Bootstrap;

use Framework\Foundation\Application;
use Framework\Config\Repository;

class LoadConfiguration
{
    public function bootstrap(Application $app): void
    {
        // 1. Load .env file (Naive implementation)
        // Real Laravel uses vlucas/phpdotenv. We will do a basic parse here.
        $envPath = $app->basePath('.env');
        if (file_exists($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (str_starts_with(trim($line), '#')) continue;
                
                list($name, $value) = explode('=', $line, 2);
                putenv(trim($name) . '=' . trim($value));
            }
        }

        // 2. Setup the Config Repository
        $config = new Repository();
        $app->instance(Repository::class, $config);

        // 3. Load all PHP files in the config/ directory
        $configPath = $app->configPath();
        if (is_dir($configPath)) {
            foreach (glob($configPath . '/*.php') as $file) {
                // 'app.php' becomes 'app'
                $key = basename($file, '.php'); 
                $config->set($key, require $file);
            }
        }
    }
}
```

### Update the Kernel

Add the new bootstrapper to the Kernel so it runs early.

```php
// In src/Http/Kernel.php, update the $bootstrappers array:

    protected array $bootstrappers = [
        \Framework\Foundation\Bootstrap\LoadConfiguration::class, // <-- ADD THIS
        \Framework\Foundation\Bootstrap\RegisterProviders::class,
        \Framework\Foundation\Bootstrap\BootProviders::class,
    ];
```

### Create Sample Files

**`.env`**:
```env
APP_NAME=LaravelClone
APP_ENV=local
```

**`config/app.php`**:
```php
<?php

return [
    'name' => env('APP_NAME', 'DefaultApp'),
    'env'  => env('APP_ENV', 'production'),
];
```

---

## ✅ Verify

Update your `HomeController@index` to test the config:

```php
    public function index(Request $request)
    {
        $appName = config('app.name');
        return "App Name from Config/Env: " . $appName;
    }
```

Run the server:
```bash
php -S 0.0.0.0:8000 -t public
```

Open `http://localhost:8000/`. You should see:
```
App Name from Config/Env: LaravelClone
```

Change `APP_NAME` in `.env`, refresh the page, and watch it update without touching any PHP code!

---

## 📌 What We Built

| Element | Purpose |
|---------|---------|
| `.env` | Holds secrets and environment-specific variables. |
| `config/*.php` | Version-controlled arrays defining application settings. |
| `Config\Repository` | Central registry for configuration data, accessed via dot notation. |
| `LoadConfiguration` | Bootstrapper that parses `.env` and populates the `Repository`. |

---

## ⚠️ Simplifications vs Laravel

| Laravel | Our Implementation | Reason |
|---------|-------------------|--------|
| `vlucas/phpdotenv` library | Basic `putenv()` | A robust `.env` parser requires handling quotes, multiline variables, and nested expansion. |
| Config Caching | Skipped | Optimization feature out of scope. |
| Extensive default configs | Single `app.php` | We don't have database, mail, or queue systems to configure yet. |

---

**Next:** [Step 12 — Validation →](./12-validation.md)
