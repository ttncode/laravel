# Step 01: Entry Point

---

## 🚩 The Problem

Without a framework, PHP maps URLs directly to files on disk:

```
GET /users         → /var/www/users.php
GET /users/profile → /var/www/users/profile.php
GET /about         → /var/www/about.php
```

This creates an immediate structural problem: **there is no single place where all requests pass through.**

Consider what happens when you need authentication. You write it in `auth.php` and add `require 'auth.php'` to the top of every file. Miss one file — no auth. Add a new file — remember to add the require. Rename `auth.php` — update every reference.

The same applies to logging, error handling, CORS headers, rate limiting, and every other cross-cutting concern. Each file is an island.

---

## 🔍 Why Naive Solutions Fail

**Attempt 1: Shared bootstrap file**

```php
// Every file starts with this
<?php require 'bootstrap.php';
```

Still requires every file to remember to include it. One missed file breaks the whole chain. And your URL structure is still dictated by your file structure — you can't have a clean URL like `/users/42` without `.htaccess` hacks or ugly query strings like `/users.php?id=42`.

**Attempt 2: Wildcard `.htaccess` redirect**

```
RewriteRule .* index.php [L]
```

This is actually the right instinct — but without a framework behind `index.php`, you're just pushing all the complexity into one giant file.

**The deeper issue: no shared lifecycle**

When 1,000 users hit your server concurrently, PHP spawns 1,000 processes. In the file-per-URL model, each process independently initializes everything it needs from scratch — database connections, config, logger — with no central coordination.

A **single entry point** gives you a guaranteed place to:
- Initialize shared resources once per process
- Apply rules to every request without exception
- Control the full lifecycle from request to response

---

## 💡 The Solution: Front Controller Pattern

Route every HTTP request — regardless of URL — to a **single PHP file**.

```
Browser → Web Server (Nginx/Apache)
             │
             ▼ (every URL)
         public/index.php
             │
             ▼ (framework takes over)
         Your application
             │
             ▼
         Response → Browser
```

The web server is configured to send all requests to `public/index.php`. The framework reads the URL from the request and decides what to do with it — this is routing (Step 06). The file itself stays thin and unchanging.

This pattern is used by every major PHP framework: Laravel, Symfony, Slim, CodeIgniter 4, Yii 2, and Zend/Laminas.

---

## 🏗 Implementation

### Step 1.1 — Create the project

Starting from a completely empty directory:

```bash
mkdir laravel-clone
cd laravel-clone
mkdir public
```

### Step 1.2 — Create `composer.json`

Composer does two things here:
1. Manages third-party dependencies (none yet)
2. Registers a PSR-4 autoloader so PHP finds our classes by namespace — no manual `require` calls

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

**PSR-4 mapping explained:**
- `Framework\Http\Kernel` → `src/Http/Kernel.php`
- `App\Controllers\HomeController` → `app/Controllers/HomeController.php`

Run:

```bash
composer install
```

This generates `vendor/autoload.php`. Every file in the project will start with `require_once __DIR__ . '/../vendor/autoload.php'` to activate this autoloader.

### Step 1.3 — Create `public/index.php`

At this stage, we have no Application, no Kernel, no Router. This file proves the entry point works.

```php
<?php

// Load Composer's autoloader.
// This is the ONLY require_once in the entire framework.
// Every other class loads automatically via PSR-4.
require_once __DIR__ . '/../vendor/autoload.php';

echo 'Entry point reached.';
```

**Why `public/` is the document root?**

The `public/` directory is the only folder exposed to the web. Everything else — `src/`, `app/`, `bootstrap/`, `config/` — lives outside the web root and cannot be accessed directly via URL. This is a security boundary: your source code, config files, and secrets are never directly downloadable.

### Step 1.4 — Configure your web server

**For development (PHP built-in server):**

```bash
php -S 0.0.0.0:8000 -t public
```

The `-t public` flag sets `public/` as the document root. `0.0.0.0` binds to all interfaces (accessible from outside the machine if needed; use `localhost` if you only need local access).

**For production (Nginx config snippet):**

```nginx
root /var/www/laravel-clone/public;
index index.php;

location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    include fastcgi_params;
}
```

The `try_files` directive is what makes the front controller work: if no file matches the URL, Nginx falls through to `/index.php`.

---

## ✅ Verify

```bash
php -S 0.0.0.0:8000 -t public
```

Test 1 — Main URL:
```
GET http://localhost:8000/
→ "Entry point reached."
```

Test 2 — Any other URL:
```
GET http://localhost:8000/users/42/profile
→ "Entry point reached."
```

Both URLs produce the same output. The front controller is intercepting every request. ✓

---

## 📌 What We Built

| File | Purpose |
|------|---------|
| `composer.json` | PSR-4 autoloader config + dependency management |
| `vendor/autoload.php` | Generated by Composer — activates class autoloading |
| `public/index.php` | Single entry point — all HTTP requests arrive here |

**Directory structure after this step:**

```
laravel-clone/
├── composer.json
├── composer.lock
├── vendor/
│   └── autoload.php
└── public/
    └── index.php
```

`public/index.php` will grow across the next five steps as the framework is built piece by piece. Its final form is reached in Step 05.

---

## ⚠️ Simplifications vs Laravel

| Laravel | Our Clone | Reason |
|---------|-----------|--------|
| Checks for `storage/framework/maintenance.php` | Skipped | Maintenance mode is an ops concern |
| Defines `LARAVEL_START` timestamp | Skipped | Performance monitoring, not architecture |
| Uses `$app->handleRequest(Request::capture())` | We call `$kernel->handle($request)` directly (Step 05) | More explicit — each step is transparent |

---

**Next:** [Step 02 — IoC Container →](./02-container.md)
