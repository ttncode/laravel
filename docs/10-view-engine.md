# Step 10: View Engine

---

## 🚩 The Problem

Right now, if we want to return an HTML page from our Controller, we have to do this:

```php
public function index()
{
    $title = "Welcome";
    $users = ['Alice', 'Bob'];
    
    $html = "<html><head><title>{$title}</title></head><body><ul>";
    foreach ($users as $user) {
        $html .= "<li>{$user}</li>";
    }
    $html .= "</ul></body></html>";
    
    return $html;
}
```

**Why is this bad?**
1. **Unreadable:** Mixing complex HTML into PHP strings is visually noisy and prone to syntax errors.
2. **Separation of Concerns:** Controllers should handle *business logic* and data retrieval, not *presentation*.
3. **No Editor Support:** Your IDE cannot provide HTML autocompletion inside a PHP string.

---

## 💡 The Solution: A View Component

We extract the HTML into separate template files. The Controller retrieves data, and passes it to the **View Engine**. The View Engine loads the template, injects the variables, and renders the final HTML string.

In PHP, we can use **Output Buffering** (`ob_start()` and `ob_get_clean()`) to isolate a file's output. Instead of echoing directly to the browser, we capture the echoed HTML into a string, which we then return as a `Response`.

---

## 🏗 Implementation

```bash
mkdir -p src/View
touch src/View/View.php
touch src/helpers.php
mkdir -p resources/views
touch resources/views/home.php
```

### File: `src/View/View.php`

```php
<?php

namespace Framework\View;

class View
{
    protected string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
    }

    /**
     * Render a view template with the given data.
     * 
     * Example: render('home', ['title' => 'Hello'])
     * Looks for: resources/views/home.php
     */
    public function render(string $view, array $data = []): string
    {
        $path = $this->basePath . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $view) . '.php';

        if (! file_exists($path)) {
            throw new \RuntimeException("View [{$view}] not found at [{$path}].");
        }

        // Extract the array into distinct variables.
        // e.g. ['title' => 'Hello'] becomes $title = 'Hello'
        extract($data);

        // Start output buffering
        ob_start();

        // Include the file. Any HTML or echo statements inside will be 
        // captured by the buffer instead of sent to the browser.
        require $path;

        // Return the captured buffer and turn off buffering
        return ob_get_clean();
    }
}
```

### File: `src/helpers.php`

In Laravel, you rarely type `(new View)->render()`. You use global helper functions.

```php
<?php

use Framework\Container\Container;
use Framework\View\View;

if (! function_exists('view')) {
    /**
     * Global helper to render a view.
     */
    function view(string $name, array $data = []): string
    {
        // Resolve the View engine from the container
        $viewEngine = Container::getInstance()->make(View::class);
        
        return $viewEngine->render($name, $data);
    }
}
```

We need to tell Composer to always load this `helpers.php` file. Update your `composer.json`:

```json
    "autoload": {
        "psr-4": {
            "App\\": "app/",
            "Framework\\": "src/"
        },
        "files": [
            "src/helpers.php"
        ]
    },
```
Run `composer dump-autoload` after making this change!

### Update: `bootstrap/app.php`

We need to bind the `View` class into the container so the helper can resolve it, passing it the correct path to the `resources/views` directory.

Add this right before `return $app;`:

```php
// ...

$app->singleton(\Framework\View\View::class, function ($app) {
    return new \Framework\View\View($app->resourcePath('views'));
});

return $app;
```

### Create a Template: `resources/views/home.php`

```php
<!DOCTYPE html>
<html>
<head>
    <title><?= htmlspecialchars($title) ?></title>
</head>
<body>
    <h1><?= htmlspecialchars($heading) ?></h1>
    
    <ul>
        <?php foreach ($users as $user): ?>
            <li><?= htmlspecialchars($user) ?></li>
        <?php endforeach; ?>
    </ul>
</body>
</html>
```
*(Always use `htmlspecialchars` when echoing variables to prevent XSS attacks!)*

### Update the Controller: `app/Controllers/HomeController.php`

```php
<?php

namespace App\Controllers;

use Framework\Http\Request;

class HomeController
{
    public function index(Request $request)
    {
        // The business logic is clean and separate from presentation.
        return view('home', [
            'title'   => 'Laravel Clone',
            'heading' => 'Users List',
            'users'   => ['Alice', 'Bob', 'Charlie']
        ]);
    }
}
```

---

## ✅ Verify

1. Run `composer dump-autoload` (to load `helpers.php`).
2. Run the server: `php -S 0.0.0.0:8000 -t public`
3. Open `http://localhost:8000/`.

You should see a properly formatted HTML page with a list of users.

---

## 📌 What We Built

| Element | Purpose |
|---------|---------|
| `View` Engine | Uses Output Buffering to render PHP templates into strings. |
| `view()` helper | Global function for developer convenience. |
| `resources/views` | Dedicated directory for presentation files. |

---

## ⚠️ Simplifications vs Laravel

| Laravel | Our Implementation | Reason |
|---------|-------------------|--------|
| Blade Template Engine (`.blade.php`) | Plain PHP templates | Blade requires a complex compiler to parse `@if`, `@foreach`, and components into PHP. Plain PHP is sufficient to teach the core concept. |
| View Composers | Skipped | Advanced feature. |
| View Factory / Finder | Single `View` class | Laravel splits the logic of finding files on disk and compiling them into multiple classes. |

---

**Next:** [Step 11 — Config & Environment →](./11-config-env.md)
