# Step 09: Controller

---

## 1. 🎯 Purpose (WHY)

Controllers organize request-handling logic. Instead of cramming everything into route closures, controllers group related actions into a class:

```
UserController
  - index()  → list users
  - show()   → show one user
  - store()  → create a user
  - update() → modify a user
  - destroy() → delete a user
```

This is the "C" in MVC. Controllers receive a **Request**, interact with models/services, and return a **Response** (or a View).

**Laravel equivalent:** `Illuminate\Routing\Controller`

---

## 2. 🧠 Concept (WHAT)

Laravel's base Controller is intentionally thin — it's mostly a marker class that provides:
- Middleware registration per method
- `callAction()` dispatch

Our version is even simpler: a base class that controller actions can extend, providing helper methods for common response patterns.

The real work is done by the **Router** (Step 7) which resolves and calls the controller. The controller itself is just a PHP class — the container handles dependency injection via its constructor.

---

## 3. 🏗 Implementation (HOW)

### File: `laravel-clone/src/Routing/Controller.php`

```php
<?php

namespace Framework\Routing;

use Framework\Http\Request;
use Framework\Http\Response;
use Framework\Http\JsonResponse;

abstract class Controller
{
    /**
     * Return an HTML response.
     */
    protected function response(string $content, int $status = 200, array $headers = []): Response
    {
        return new Response($content, $status, $headers);
    }

    /**
     * Return a JSON response.
     */
    protected function json(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, $headers);
    }

    /**
     * Return a redirect response.
     */
    protected function redirect(string $url, int $status = 302): Response
    {
        return Response::redirect($url, $status);
    }

    /**
     * Abort with an HTTP error.
     */
    protected function abort(int $status, string $message = ''): never
    {
        throw new \RuntimeException($message ?: "HTTP {$status}");
        // In a full framework, this would throw an HttpException
        // that the Kernel renders with the correct status code
    }
}
```

### File: `laravel-clone/app/Controllers/HomeController.php`

```php
<?php

namespace App\Controllers;

use Framework\Http\Request;
use Framework\Http\Response;
use Framework\Routing\Controller;

class HomeController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->response('<h1>Welcome to Laravel Clone!</h1>');
    }
}
```

### File: `laravel-clone/app/Controllers/UserController.php`

```php
<?php

namespace App\Controllers;

use Framework\Http\Request;
use Framework\Http\Response;
use Framework\Routing\Controller;

class UserController extends Controller
{
    /**
     * Constructor injection — the container provides UserRepository automatically.
     * For now, we use a simple array as a fake "database".
     */
    private array $users = [
        1 => ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'],
        2 => ['id' => 2, 'name' => 'Bob',   'email' => 'bob@example.com'],
    ];

    /**
     * GET /users
     * List all users.
     */
    public function index(Request $request): Response
    {
        if ($request->expectsJson()) {
            return $this->json($this->users);
        }

        $html = '<ul>';
        foreach ($this->users as $user) {
            $html .= "<li>{$user['name']} — {$user['email']}</li>";
        }
        $html .= '</ul>';

        return $this->response($html);
    }

    /**
     * GET /users/{id}
     * Show a single user.
     */
    public function show(Request $request, string $id): Response
    {
        $user = $this->users[(int) $id] ?? null;

        if (! $user) {
            return $this->json(['error' => 'User not found'], 404);
        }

        return $this->json($user);
    }

    /**
     * POST /users
     * Create a new user.
     */
    public function store(Request $request): Response
    {
        $name  = $request->input('name');
        $email = $request->input('email');

        if (! $name || ! $email) {
            return $this->json(['error' => 'name and email are required'], 422);
        }

        $newUser = [
            'id'    => count($this->users) + 1,
            'name'  => $name,
            'email' => $email,
        ];

        // In a real app, save to database here

        return $this->json($newUser, 201);
    }
}
```

### Updated: `laravel-clone/routes/web.php`

```php
<?php

/** @var \Framework\Routing\Router $router */

use App\Controllers\HomeController;
use App\Controllers\UserController;

$router->get('/', [HomeController::class, 'index']);

$router->get('/users',       [UserController::class, 'index']);
$router->get('/users/{id}',  [UserController::class, 'show']);
$router->post('/users',      [UserController::class, 'store']);
```

---

## 4. 🔗 Integration

The Router (Step 7) calls controllers via the Container:

```php
// In Router::callAction()
$controller = $this->container->make($controllerClass);  // DI via container
$response   = $this->container->call([$controller, $method], $params);
```

The container:
1. Creates `UserController` (injecting any constructor dependencies)
2. Calls `show(Request $request, string $id)` injecting `$request` from the container and `$id` from route parameters

---

## 5. ✅ Usage Example

### Testing in browser (after running all steps)

```bash
php -S localhost:8000 -t public
```

```
GET  http://localhost:8000/           → HomeController@index
GET  http://localhost:8000/users      → UserController@index
GET  http://localhost:8000/users/1    → UserController@show, id=1
POST http://localhost:8000/users      → UserController@store
```

### Using constructor injection

```php
class UserController extends Controller
{
    // Container resolves Logger automatically
    public function __construct(private Logger $logger) {}

    public function index(Request $request): Response
    {
        $this->logger->info('Listing users');
        return $this->json([/* ... */]);
    }
}
```

### Returning different response types

```php
class ProductController extends Controller
{
    public function show(Request $request, string $id): Response
    {
        $product = $this->findProduct($id);

        // Let Router's toResponse() handle plain array
        // OR explicitly build the response:
        return $request->expectsJson()
            ? $this->json($product)
            : $this->response(view('products.show', compact('product')));
    }
}
```

---

## 6. 📌 Key Elements

| Element | Purpose |
|---------|---------|
| `Controller` base class | Provides `response()`, `json()`, `redirect()` helpers |
| Constructor injection | Container injects services into controller |
| Method parameters | Route params (`{id}`) passed as method arguments |
| `Request` parameter | Auto-resolved from container by type hint |
| `app/Controllers/` | Where user controllers live |

---

## 7. ⚠️ Simplifications Made

| Laravel | Our Clone | Reason |
|---------|-----------|--------|
| `Controller` has `callAction()`, middleware registration | Just helper methods | Dispatch is Router's job |
| `Controller::middleware()` per-method middleware | Not implemented | Route-level middleware covers most cases |
| `FormRequest` injection for validation | Inline validation | Covered in Step 12 |
| `__invoke()` single-action controllers | Can use closures instead | Same concept |
| `ResponseFactory` for view/redirect/json | Methods on base Controller | Simpler |

---

**Next:** [Step 10 — View Engine →](./10-view-engine.md)
