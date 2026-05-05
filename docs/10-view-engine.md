# Step 10: View Engine

---

## 1. 🎯 Purpose (WHY)

The **View Engine** separates presentation from logic. Instead of echoing HTML strings inside controllers, views live in dedicated template files — cleaner, reusable, designer-friendly.

Without a view engine:
```php
// In controller — ugly, unmaintainable
return $this->response("<html><body><h1>Hello {$user['name']}</h1>...</body></html>");
```

With a view engine:
```php
// Clean — controller stays logic-only
return $this->view('users.show', ['user' => $user]);
```

**Laravel equivalent:** `Illuminate\View\View` + `Illuminate\View\Factory` + Blade compiler

We implement a **minimal PHP template engine** — no Blade compilation, but the same `view()` API and `$this->view()` pattern.

---

## 2. 🧠 Concept (WHAT)

Our view engine does three things:

1. **Locate** the template file (`users.show` → `resources/views/users/show.php`)
2. **Extract** variables into the template's scope
3. **Capture** the output via output buffering

```
view('users.show', ['user' => $user])
  → find resources/views/users/show.php
  → extract(['user' => $user])       // $user is now available in template
  → ob_start()
  → include 'users/show.php'
  → $html = ob_get_clean()
  → return $html
```

Template files are plain PHP — no special syntax required. You CAN add a thin Blade-like preprocessing layer later (Step extension), but for now we use `<?= ?>` and `<?php ?>`.

---

## 3. 🏗 Implementation (HOW)

### File: `laravel-clone/src/View/View.php`

```php
<?php

namespace Framework\View;

class View
{
    protected string $path;
    protected array  $data;

    public function __construct(string $path, array $data = [])
    {
        $this->path = $path;
        $this->data = $data;
    }

    /**
     * Render the view to a string.
     *
     * Uses output buffering + extract() to make $data available
     * as local variables inside the template file.
     */
    public function render(): string
    {
        if (! file_exists($this->path)) {
            throw new \RuntimeException("View not found: [{$this->path}]");
        }

        // Make $data keys available as local variables
        extract($this->data, EXTR_SKIP);

        // Capture output
        ob_start();

        try {
            include $this->path;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return ob_get_clean();
    }

    /**
     * Render when cast to string.
     */
    public function __toString(): string
    {
        return $this->render();
    }

    /**
     * Add more data to the view.
     */
    public function with(string|array $key, mixed $value = null): static
    {
        if (is_array($key)) {
            $this->data = array_merge($this->data, $key);
        } else {
            $this->data[$key] = $value;
        }

        return $this;
    }
}
```

### File: `laravel-clone/src/View/ViewFactory.php`

```php
<?php

namespace Framework\View;

class ViewFactory
{
    /**
     * Base path for view files.
     */
    protected string $viewsPath;

    public function __construct(string $viewsPath)
    {
        $this->viewsPath = rtrim($viewsPath, '/\\');
    }

    /**
     * Create a View instance.
     *
     * @param string $name   Dot-notation name: 'users.show' → 'users/show.php'
     * @param array  $data   Variables to pass to the view
     */
    public function make(string $name, array $data = []): View
    {
        $path = $this->resolvePath($name);

        return new View($path, $data);
    }

    /**
     * Render a view directly to a string.
     */
    public function render(string $name, array $data = []): string
    {
        return $this->make($name, $data)->render();
    }

    /**
     * Convert dot-notation name to file path.
     * 'users.show' → '/path/to/resources/views/users/show.php'
     */
    protected function resolvePath(string $name): string
    {
        $relative = str_replace('.', DIRECTORY_SEPARATOR, $name) . '.php';

        return $this->viewsPath . DIRECTORY_SEPARATOR . $relative;
    }
}
```

### File: `laravel-clone/src/View/ViewServiceProvider.php`

```php
<?php

namespace Framework\View;

use Framework\Support\ServiceProvider;

class ViewServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ViewFactory::class, function ($app) {
            $viewsPath = $app->resourcePath('views');

            return new ViewFactory($viewsPath);
        });

        $this->app->alias(ViewFactory::class, 'view');
    }
}
```

### Updated: `laravel-clone/src/Routing/Controller.php`

Add a `view()` helper method:

```php
<?php

namespace Framework\Routing;

use Framework\Http\Request;
use Framework\Http\Response;
use Framework\Http\JsonResponse;
use Framework\View\ViewFactory;

abstract class Controller
{
    /**
     * Render a view and return an HTML response.
     */
    protected function view(string $viewName, array $data = [], int $status = 200): Response
    {
        // Resolve the ViewFactory from the container
        $factory = app()->make(ViewFactory::class);
        $html    = $factory->render($viewName, $data);

        return new Response($html, $status);
    }

    protected function response(string $content, int $status = 200, array $headers = []): Response
    {
        return new Response($content, $status, $headers);
    }

    protected function json(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, $headers);
    }

    protected function redirect(string $url, int $status = 302): Response
    {
        return Response::redirect($url, $status);
    }

    protected function abort(int $status, string $message = ''): never
    {
        throw new \RuntimeException($message ?: "HTTP {$status}");
    }
}
```

### Global `view()` helper: `laravel-clone/src/helpers.php`

```php
<?php

if (! function_exists('app')) {
    function app(string $abstract = null): mixed
    {
        $container = \Framework\Container\Container::getInstance();

        if ($abstract === null) {
            return $container;
        }

        return $container->make($abstract);
    }
}

if (! function_exists('view')) {
    function view(string $name, array $data = []): \Framework\View\View
    {
        return app(\Framework\View\ViewFactory::class)->make($name, $data);
    }
}

if (! function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return app('config')->get($key, $default);
    }
}

if (! function_exists('redirect')) {
    function redirect(string $url, int $status = 302): \Framework\Http\Response
    {
        return \Framework\Http\Response::redirect($url, $status);
    }
}
```

Register in `composer.json`:
```json
{
    "autoload": {
        "psr-4": {
            "App\\": "app/",
            "Framework\\": "src/"
        },
        "files": [
            "src/helpers.php"
        ]
    }
}
```

### Template files

### `laravel-clone/resources/views/layout.php`

```php
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'Laravel Clone') ?></title>
</head>
<body>
    <nav>
        <a href="/">Home</a> |
        <a href="/users">Users</a>
    </nav>
    <hr>
    <main>
        <?= $content ?? '' ?>
    </main>
</body>
</html>
```

### `laravel-clone/resources/views/home.php`

```php
<?php $title = 'Welcome'; ?>
<h1>Welcome to Laravel Clone!</h1>
<p>A minimal PHP framework inspired by Laravel 13.</p>
<a href="/users">View Users →</a>
```

### `laravel-clone/resources/views/users/index.php`

```php
<?php $title = 'Users'; ?>
<h1>All Users</h1>
<ul>
    <?php foreach ($users as $user): ?>
        <li>
            <a href="/users/<?= $user['id'] ?>">
                <?= htmlspecialchars($user['name']) ?>
            </a>
            — <?= htmlspecialchars($user['email']) ?>
        </li>
    <?php endforeach; ?>
</ul>
<a href="/">← Back</a>
```

### `laravel-clone/resources/views/users/show.php`

```php
<?php $title = $user['name']; ?>
<h1><?= htmlspecialchars($user['name']) ?></h1>
<p>Email: <?= htmlspecialchars($user['email']) ?></p>
<a href="/users">← Back to Users</a>
```

---

## 4. 🔗 Integration

Register the `ViewServiceProvider` in `config/app.php`:

```php
'providers' => [
    \Framework\Routing\RoutingServiceProvider::class,
    \Framework\View\ViewServiceProvider::class,       // ← add this
    \App\Providers\AppServiceProvider::class,
],
```

Update the `UserController` to use views:

```php
public function index(Request $request): Response
{
    $users = [
        ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'],
        ['id' => 2, 'name' => 'Bob',   'email' => 'bob@example.com'],
    ];

    if ($request->expectsJson()) {
        return $this->json($users);
    }

    return $this->view('users.index', compact('users'));
}
```

---

## 5. ✅ Usage Example

```php
// In a controller:
return $this->view('users.show', ['user' => $user]);

// In a route closure:
$router->get('/about', function () {
    return view('about');
});

// Nested views:
$router->get('/posts/{slug}', function (string $slug) {
    return view('posts.show', ['post' => findPost($slug)]);
});
```

### Template with escaping

```php
<!-- resources/views/posts/show.php -->
<h1><?= htmlspecialchars($post['title']) ?></h1>
<p><?= htmlspecialchars($post['body']) ?></p>

<!-- Loop -->
<?php foreach ($post['tags'] as $tag): ?>
    <span class="tag"><?= htmlspecialchars($tag) ?></span>
<?php endforeach; ?>

<!-- Conditional -->
<?php if ($post['published']): ?>
    <p>Published</p>
<?php else: ?>
    <p>Draft</p>
<?php endif; ?>
```

---

## 6. 📌 Key Elements

| Element | Purpose |
|---------|---------|
| `View::render()` | `extract()` + `ob_start()` + `include` |
| `ViewFactory::make()` | Converts dot-notation to file path |
| `ViewServiceProvider` | Registers `ViewFactory` as singleton |
| `resources/views/` | Template files location |
| `src/helpers.php` | Global `view()`, `app()`, `config()` functions |
| `$title`, `$content` in templates | Simple variable extraction |

---

## 7. ⚠️ Simplifications Made

| Laravel | Our Clone | Reason |
|---------|-----------|--------|
| Blade compiler (`.blade.php` → `.php` cache) | Plain PHP templates | No compilation step |
| `@extends`, `@section`, `@yield` | Not implemented | Use PHP `include` for layouts |
| `@foreach`, `@if` Blade directives | Use `<?php foreach ?>` | Same output |
| `{{ $var }}` auto-escaping | Use `<?= htmlspecialchars() ?>` | Manual escaping |
| View composers / creators | Not implemented | Extra hook system |
| Namespaced views (`vendor::views`) | Not implemented | Package views |
| View caching | Not implemented | Optimization |

---

**Next:** [Step 11 — Config & Env →](./11-config-env.md)
