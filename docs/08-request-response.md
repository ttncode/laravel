# Step 08: HTTP Request & Response

---

## 1. 🎯 Purpose (WHY)

The **Request** and **Response** objects are the framework's first-class representations of HTTP. Instead of using `$_GET`, `$_POST`, `$_SERVER` directly everywhere (messy, hard to test), the Request object wraps the incoming data into a clean API.

The **Response** object wraps the outgoing data — status code, headers, body — and provides a `send()` method to actually output it.

**Laravel equivalent:**
- `Illuminate\Http\Request` (wraps Symfony's HttpFoundation Request)
- `Illuminate\Http\Response` (wraps Symfony's Response)

We implement our own — simpler but same interface.

---

## 2. 🧠 Concept (WHAT)

**Request** answers:
- What HTTP method? (`GET`, `POST`, etc.)
- What URI was requested?
- What headers were sent?
- What query string params?
- What POST body data?
- Is it JSON? Is it AJAX?

**Response** answers:
- What status code to send? (`200`, `404`, `500`)
- What headers to include?
- What body content?

The key design principle: these objects are **value objects** — they carry data, not behavior. The Request doesn't route itself. The Response doesn't know where it's going.

---

## 3. 🏗 Implementation (HOW)

### File: `laravel-clone/src/Http/Request.php`

```php
<?php

namespace Framework\Http;

class Request
{
    protected string $method;
    protected string $uri;
    protected array  $query;       // $_GET
    protected array  $input;       // $_POST or JSON body
    protected array  $headers;
    protected array  $server;
    protected array  $files;
    protected string $body;        // raw request body

    public function __construct(
        string $method,
        string $uri,
        array  $query   = [],
        array  $input   = [],
        array  $headers = [],
        array  $server  = [],
        array  $files   = [],
        string $body    = ''
    ) {
        $this->method  = strtoupper($method);
        $this->uri     = $uri;
        $this->query   = $query;
        $this->input   = $input;
        $this->headers = $headers;
        $this->server  = $server;
        $this->files   = $files;
        $this->body    = $body;
    }

    /**
     * Create a Request from PHP's superglobals.
     * This is the factory method called in public/index.php.
     */
    public static function capture(): static
    {
        // Extract headers from $_SERVER (HTTP_* keys)
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[strtolower($name)] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }

        // Raw body for JSON/PUT/PATCH
        $body  = file_get_contents('php://input') ?: '';
        $input = $_POST;

        // Parse JSON body if content-type is application/json
        if (isset($headers['content-type'])
            && str_contains($headers['content-type'], 'application/json')
            && $body !== ''
        ) {
            $input = json_decode($body, true) ?? [];
        }

        // Clean URI — strip query string
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        return new static(
            method:  $_SERVER['REQUEST_METHOD'] ?? 'GET',
            uri:     $uri,
            query:   $_GET,
            input:   $input,
            headers: $headers,
            server:  $_SERVER,
            files:   $_FILES,
            body:    $body,
        );
    }

    // ─── Method & URI ─────────────────────────────────────────────────────────

    public function method(): string { return $this->method; }
    public function uri(): string    { return $this->uri; }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function isGet(): bool    { return $this->method === 'GET'; }
    public function isPost(): bool   { return $this->method === 'POST'; }
    public function isPut(): bool    { return $this->method === 'PUT'; }
    public function isPatch(): bool  { return $this->method === 'PATCH'; }
    public function isDelete(): bool { return $this->method === 'DELETE'; }

    // ─── Input ────────────────────────────────────────────────────────────────

    /**
     * Get a value from POST body or JSON body.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->input[$key] ?? $default;
    }

    /**
     * Get a query string parameter.
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Get from input first, then query string.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->input[$key] ?? $this->query[$key] ?? $default;
    }

    /**
     * Get all input (POST + query merged).
     */
    public function all(): array
    {
        return array_merge($this->query, $this->input);
    }

    /**
     * Check if input key exists.
     */
    public function has(string $key): bool
    {
        return isset($this->input[$key]) || isset($this->query[$key]);
    }

    /**
     * Get only specific keys from input.
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->all(), array_flip($keys));
    }

    /**
     * Get all except specific keys.
     */
    public function except(array $keys): array
    {
        return array_diff_key($this->all(), array_flip($keys));
    }

    // ─── Headers ─────────────────────────────────────────────────────────────

    public function header(string $key, ?string $default = null): ?string
    {
        return $this->headers[strtolower($key)] ?? $default;
    }

    public function hasHeader(string $key): bool
    {
        return isset($this->headers[strtolower($key)]);
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('authorization');

        if ($header && str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return null;
    }

    // ─── Type Detection ───────────────────────────────────────────────────────

    public function isJson(): bool
    {
        return str_contains($this->header('content-type', ''), 'application/json');
    }

    public function expectsJson(): bool
    {
        return str_contains($this->header('accept', ''), 'application/json');
    }

    public function isAjax(): bool
    {
        return $this->header('x-requested-with') === 'XMLHttpRequest';
    }

    // ─── Raw body ────────────────────────────────────────────────────────────

    public function getContent(): string { return $this->body; }
    public function json(string $key = null, mixed $default = null): mixed
    {
        $data = json_decode($this->body, true) ?? [];

        if ($key === null) {
            return $data;
        }

        return $data[$key] ?? $default;
    }
}
```

### File: `laravel-clone/src/Http/Response.php`

```php
<?php

namespace Framework\Http;

class Response
{
    protected int    $statusCode;
    protected string $content;
    protected array  $headers;

    public function __construct(
        string $content    = '',
        int    $statusCode = 200,
        array  $headers    = []
    ) {
        $this->content    = $content;
        $this->statusCode = $statusCode;
        $this->headers    = array_merge(
            ['Content-Type' => 'text/html; charset=UTF-8'],
            $headers
        );
    }

    // ─── Factory Methods ──────────────────────────────────────────────────────

    /**
     * Create a JSON response.
     */
    public static function json(mixed $data, int $status = 200): static
    {
        return new static(
            content:    json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            statusCode: $status,
            headers:    ['Content-Type' => 'application/json']
        );
    }

    /**
     * Create a redirect response.
     */
    public static function redirect(string $url, int $status = 302): static
    {
        return new static(
            content:    '',
            statusCode: $status,
            headers:    ['Location' => $url]
        );
    }

    // ─── Fluent Setters ───────────────────────────────────────────────────────

    public function setHeader(string $name, string $value): static
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function withHeaders(array $headers): static
    {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }

        return $this;
    }

    public function setStatusCode(int $code): static
    {
        $this->statusCode = $code;

        return $this;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    // ─── Getters ──────────────────────────────────────────────────────────────

    public function getStatusCode(): int  { return $this->statusCode; }
    public function getContent(): string  { return $this->content; }
    public function getHeaders(): array   { return $this->headers; }

    // ─── Output ───────────────────────────────────────────────────────────────

    /**
     * Send the response to the browser.
     * Called from public/index.php.
     */
    public function send(): void
    {
        // Send HTTP status
        http_response_code($this->statusCode);

        // Send headers
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        // Send body
        echo $this->content;
    }
}
```

### File: `laravel-clone/src/Http/JsonResponse.php`

```php
<?php

namespace Framework\Http;

class JsonResponse extends Response
{
    public function __construct(mixed $data, int $status = 200, array $headers = [])
    {
        parent::__construct(
            content:    json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            statusCode: $status,
            headers:    array_merge(['Content-Type' => 'application/json'], $headers)
        );
    }
}
```

---

## 4. 🔗 Integration

- `public/index.php` calls `Request::capture()` and `$response->send()`
- The Kernel passes `$request` through the Pipeline
- Controllers return strings, arrays, or `Response` objects — Router's `toResponse()` normalizes them
- Middleware receives `$request` and `$response`

---

## 5. ✅ Usage Example

```php
// In a controller:
class UserController
{
    public function show(Request $request, string $id): Response
    {
        $user = ['id' => $id, 'name' => 'Alice'];

        // JSON response
        if ($request->expectsJson()) {
            return Response::json($user);
        }

        // HTML response
        return new Response("<h1>User: {$user['name']}</h1>");
    }

    public function store(Request $request): Response
    {
        $name  = $request->input('name');
        $email = $request->input('email');

        // ... save to DB ...

        return Response::redirect('/users')->setStatusCode(201);
    }
}
```

```php
// Middleware example:
class AuthMiddleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        if (! $request->bearerToken()) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
```

---

## 6. 📌 Key Elements

| Element | Purpose |
|---------|---------|
| `Request::capture()` | Factory — reads PHP superglobals |
| `Request::input()` | POST/JSON body access |
| `Request::query()` | GET parameter access |
| `Request::all()` | Merged GET + POST |
| `Request::only()` / `except()` | Filtered input |
| `Request::isJson()` | Content-type detection |
| `Response::json()` | JSON factory method |
| `Response::redirect()` | Redirect factory |
| `Response::send()` | Outputs to browser |

---

## 7. ⚠️ Simplifications Made

| Laravel | Our Clone | Reason |
|---------|-----------|--------|
| `Request` wraps Symfony's `HttpFoundation\Request` | Standalone implementation | No Symfony dependency |
| `Request` has cookies, sessions, flash data | Omitted | Add in session step |
| File upload handling (`UploadedFile`) | Minimal `$_FILES` passthrough | Complex validation skipped |
| `Request::validate()` | Not on Request | Handled separately in Step 12 |
| `Response` wraps Symfony's `HttpFoundation\Response` | Standalone | Same reason |
| Response macros (`Responsable` interface) | Not implemented | Advanced |
| Streamed responses | Not implemented | Complex, not architectural |

---

**Next:** [Step 09 — Controller →](./09-controller.md)
