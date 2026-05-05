# Step 11: Config & Environment

---

## 1. 🎯 Purpose (WHY)

Applications need **configuration** — database credentials, API keys, feature flags — that changes between environments (local, staging, production).

The Config + Env system solves two problems:
1. **Separation**: secrets stay in `.env`, config logic stays in `config/*.php`
2. **Centralization**: one `config('key')` call, not `getenv()` scattered everywhere

**Laravel equivalent:** `Illuminate\Config\Repository` + `vlucas/phpdotenv`

---

## 2. 🧠 Concept (WHAT)

**Two-layer system:**

```
.env file (secrets, never in git)
     ↓
config/app.php, config/database.php, etc. (logic, in git)
     ↓
config('app.name')    ← dot-notation access
config('database.connections.mysql.host')
```

The `.env` file provides environment-specific values via `$_ENV`. Config files `require` them via `env('KEY', 'default')`.

```php
// config/database.php
return [
    'default' => env('DB_CONNECTION', 'sqlite'),
    'connections' => [
        'sqlite' => [
            'driver'   => 'sqlite',
            'database' => env('DB_DATABASE', storage_path('app.sqlite')),
        ],
    ],
];
```

The **Repository** stores the loaded config as a nested array and provides dot-notation `get()` and `set()`.

---

## 3. 🏗 Implementation (HOW)

### File: `laravel-clone/src/Config/Repository.php`

```php
<?php

namespace Framework\Config;

class Repository
{
    /**
     * All loaded configuration items.
     * Structure: ['app' => [...], 'database' => [...]]
     */
    protected array $items = [];

    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Get a config value by dot-notation key.
     *
     * 'app.name'                         → $items['app']['name']
     * 'database.connections.mysql.host'  → $items['database']['connections']['mysql']['host']
     *
     * Returns $default if the key doesn't exist.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->items)) {
            return $this->items[$key];
        }

        $parts  = explode('.', $key);
        $config = $this->items;

        foreach ($parts as $part) {
            if (! is_array($config) || ! array_key_exists($part, $config)) {
                return $default;
            }

            $config = $config[$part];
        }

        return $config;
    }

    /**
     * Set a config value by dot-notation key.
     *
     * $config->set('app.debug', true);
     * $config->set('app', ['name' => 'MyApp', 'debug' => false]);
     */
    public function set(string $key, mixed $value): void
    {
        $parts = explode('.', $key);

        if (count($parts) === 1) {
            $this->items[$key] = $value;
            return;
        }

        // Navigate to the right nesting level, creating arrays as needed
        $config = &$this->items;

        foreach ($parts as $i => $part) {
            if ($i === count($parts) - 1) {
                $config[$part] = $value;
            } else {
                if (! isset($config[$part]) || ! is_array($config[$part])) {
                    $config[$part] = [];
                }
                $config = &$config[$part];
            }
        }
    }

    /**
     * Check if a config key exists.
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Get all configuration items.
     */
    public function all(): array
    {
        return $this->items;
    }
}
```

### File: `laravel-clone/src/Foundation/Bootstrap/LoadEnvironmentVariables.php`

```php
<?php

namespace Framework\Foundation\Bootstrap;

use Framework\Foundation\Application;

class LoadEnvironmentVariables
{
    /**
     * Load the .env file into $_ENV before config files are loaded.
     * This runs BEFORE LoadConfiguration, so env() works in config files.
     */
    public function bootstrap(Application $app): void
    {
        $envFile = $app->basePath('.env');

        if (! file_exists($envFile)) {
            return;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            // Skip comments
            if (str_starts_with(trim($line), '#')) {
                continue;
            }

            // Parse KEY=VALUE
            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            $key   = trim($key);
            $value = trim($value);

            // Strip surrounding quotes
            if (preg_match('/^"(.*)"$/', $value, $m) || preg_match("/^'(.*)'$/", $value, $m)) {
                $value = $m[1];
            }

            // Only set if not already set (allows environment to override .env)
            if (! array_key_exists($key, $_ENV)) {
                $_ENV[$key]    = $value;
                $_SERVER[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
    }
}
```

### Updated bootstrappers in `Kernel.php`

```php
protected array $bootstrappers = [
    \Framework\Foundation\Bootstrap\LoadEnvironmentVariables::class, // ← add first
    \Framework\Foundation\Bootstrap\LoadConfiguration::class,
    \Framework\Foundation\Bootstrap\RegisterProviders::class,
    \Framework\Foundation\Bootstrap\BootProviders::class,
];
```

### Global helper: add to `laravel-clone/src/helpers.php`

```php
if (! function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        // Cast special string values
        return match (strtolower($value)) {
            'true',  '(true)'  => true,
            'false', '(false)' => false,
            'null',  '(null)'  => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}

if (! function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return app('config')->get($key, $default);
    }
}

if (! function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return app()->storagePath($path);
    }
}
```

### File: `laravel-clone/.env`

```dotenv
APP_NAME="Laravel Clone"
APP_ENV=local
APP_DEBUG=true
APP_KEY=base64:CHANGE_ME

DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/laravel-clone/storage/app.sqlite
```

### File: `laravel-clone/.env.example`

```dotenv
APP_NAME="Laravel Clone"
APP_ENV=local
APP_DEBUG=true
APP_KEY=

DB_CONNECTION=sqlite
DB_DATABASE=
```

### File: `laravel-clone/config/app.php`

```php
<?php

return [
    'name'  => env('APP_NAME', 'Laravel Clone'),
    'env'   => env('APP_ENV', 'production'),
    'debug' => env('APP_DEBUG', false),
    'key'   => env('APP_KEY'),

    'providers' => [
        \Framework\Routing\RoutingServiceProvider::class,
        \Framework\View\ViewServiceProvider::class,
        \App\Providers\AppServiceProvider::class,
    ],
];
```

### File: `laravel-clone/config/database.php`

```php
<?php

return [
    'default' => env('DB_CONNECTION', 'sqlite'),

    'connections' => [
        'sqlite' => [
            'driver'   => 'sqlite',
            'database' => env('DB_DATABASE', storage_path('app.sqlite')),
        ],
        'mysql' => [
            'driver'   => 'mysql',
            'host'     => env('DB_HOST', '127.0.0.1'),
            'port'     => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel_clone'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
        ],
    ],
];
```

---

## 4. 🔗 Integration

Bootstrap sequence with environment loading:

```
Kernel::bootstrap()
  → LoadEnvironmentVariables::bootstrap()   ← reads .env → $_ENV
  → LoadConfiguration::bootstrap()          ← reads config/*.php (uses env())
  → RegisterProviders::bootstrap()          ← reads config('app.providers')
  → BootProviders::bootstrap()              ← calls boot() on providers
```

The `LoadConfiguration` bootstrapper (Step 5) already reads all `config/*.php` files and stores them in the `Repository`. It now works correctly because `.env` is loaded first.

---

## 5. ✅ Usage Example

```php
// Read config values
$appName = config('app.name');
$debug   = config('app.debug', false);
$dbHost  = config('database.connections.mysql.host');

// Check environment in controller/middleware
if (config('app.env') === 'local') {
    // show debug info
}

// In .env:
// FEATURE_FLAG=true
$enabled = env('FEATURE_FLAG', false);

// In a config file (config/features.php):
return [
    'new_dashboard' => env('FEATURE_FLAG', false),
];

// In code:
if (config('features.new_dashboard')) {
    // use new dashboard
}
```

---

## 6. 📌 Key Elements

| Element | Purpose |
|---------|---------|
| `Repository::get()` | Dot-notation config access |
| `Repository::set()` | Dynamic config updates |
| `LoadEnvironmentVariables` | Parses `.env` → `$_ENV` |
| `LoadConfiguration` | Loads all `config/*.php` files |
| `env()` helper | Reads `$_ENV` with type casting |
| `config()` helper | Reads from Repository |
| `.env` | Machine-specific secrets (git-ignored) |
| `config/` files | Application configuration (in git) |

---

## 7. ⚠️ Simplifications Made

| Laravel | Our Clone | Reason |
|---------|-----------|--------|
| Uses `vlucas/phpdotenv` (full .env parser) | Custom simple parser | No dependency needed for basics |
| `.env` parsing handles multiline values, special chars | Single-line only | Sufficient for learning |
| `config('key')` returns `null` for missing | Same behavior | ✓ |
| Config caching (`php artisan config:cache`) | Not implemented | Optimization |
| `APP_KEY` used for encryption | Not used (no encryption) | Add when needed |
| Repository implements `ArrayAccess` | Not implemented | Access only via `get()`/`set()` |

---

**Next:** [Step 12 — Validation →](./12-validation.md)
