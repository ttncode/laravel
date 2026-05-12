# Step 04: Request & Response

---

## 🚩 The Problem

In vanilla PHP, incoming HTTP data is scattered across global superglobals:
- `$_GET` for query parameters
- `$_POST` for form data
- `$_SERVER` for headers, URIs, and methods
- `$_FILES` for uploaded files
- `php://input` for raw JSON or XML bodies

And to send data back, you use global functions:
- `header('Content-Type: application/json');`
- `http_response_code(404);`
- `echo $content;`

**Why is this bad?**
1. **Global State is Untestable:** If a controller reads directly from `$_GET`, you cannot test that controller without physically altering the global `$_GET` array in your tests.
2. **Inconsistent Interfaces:** Fetching a header requires looking inside `$_SERVER['HTTP_AUTHORIZATION']`, while fetching a query param requires `$_GET['token']`.
3. **Implicit Output:** `echo` immediately dumps output to the browser. You cannot modify the response (like adding a CORS header) after it has been echoed.

---

## 💡 The Solution: OOP Request and Response

We wrap all inputs into a single **Request object** and all outputs into a single **Response object**.

```php
// Instead of this:
$id = $_GET['id'] ?? null;
$token = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
http_response_code(200);
echo "User $id";

// We do this:
$id = $request->input('id');
$token = $request->header('Authorization');
return new Response("User $id", 200);
```

This makes the application entirely predictable: Data comes *in* via the Request, and data goes *out* via the Response. The controller becomes a pure function mapping one to the other.

*(Note: Laravel uses Symfony's `HttpFoundation` component for this under the hood. We will build a simplified version of it.)*

---

## 🏗 Implementation

```bash
mkdir -p src/Http
touch src/Http/Request.php
touch src/Http/Response.php
```

### File: `src/Http/Request.php`

```php
<?php

namespace Framework\Http;

class Request
{
    public function __construct(
        public readonly array $query,
        public readonly array $post,
        public readonly array $server,
        public readonly array $files = [],
        public readonly array $cookies = []
    ) {}

    /**
     * Create a new request instance from PHP's global variables.
     */
    public static function capture(): static
    {
        return new static($_GET, $_POST, $_SERVER, $_FILES, $_COOKIE);
    }

    /**
     * Get the request method (GET, POST, etc.)
     */
    public function method(): string
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * Get the request URI without query string.
     */
    public function path(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';
        $position = strpos($uri, '?');

        if ($position !== false) {
            $uri = substr($uri, 0, $position);
        }

        return $uri !== '/' ? rtrim($uri, '/') : '/';
    }

    /**
     * Get a value from the request (POST first, then GET).
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    /**
     * Get a header from the $_SERVER array.
     */
    public function header(string $key, mixed $default = null): mixed
    {
        // Convert "Authorization" to "HTTP_AUTHORIZATION"
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $key));

        return $this->server[$serverKey] ?? $default;
    }
}
```

### File: `src/Http/Response.php`

```php
<?php

namespace Framework\Http;

class Response
{
    public function __construct(
        protected mixed $content = '',
        protected int $status = 200,
        protected array $headers = []
    ) {}

    /**
     * Send HTTP headers and content to the browser.
     */
    public function send(): void
    {
        $this->sendHeaders();
        $this->sendContent();
    }

    protected function sendHeaders(): void
    {
        // Don't send headers if they are already sent (e.g., by PHP errors)
        if (headers_sent()) {
            return;
        }

        // Send status code
        http_response_code($this->status);

        // Send all registered headers
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}", true, $this->status);
        }
    }

    protected function sendContent(): void
    {
        echo $this->content;
    }

    public function setContent(mixed $content): static
    {
        $this->content = $content;
        return $this;
    }
}
```

---

## 📌 What We Built

| Element | Purpose |
|---------|---------|
| `Request::capture()` | Factory method that snapshots current global state into an object. |
| `Request->path()` | Extracts the clean URI needed by the Router (Step 06). |
| `Response->send()` | The only place in the framework where `echo` and `header()` are called. |

---

## ⚠️ Simplifications vs Laravel

| Laravel | Our Implementation | Reason |
|---------|-------------------|--------|
| Uses `Symfony\Component\HttpFoundation` | Custom lightweight classes | Teaches the core concept without requiring thousands of lines of Symfony code. |
| JSON parsing & File handling | Skipped | We handle basic arrays. Real JSON requires reading `php://input` stream. |
| Macroable | Skipped | Advanced extensibility feature. |

---

**Next:** [Step 05 — HTTP Kernel →](./05-http-kernel.md)
