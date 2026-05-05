# Step 03: IoC Container

---

## 1. 🎯 Purpose (WHY)

The **IoC (Inversion of Control) Container** is the heart of Laravel's architecture. It solves one problem: **how do you create objects that depend on other objects?**

Without a container:
```php
// You manually wire everything — painful to test and change
$db = new Database(new Config('db.php'));
$logger = new Logger(new FileWriter('/logs'));
$userRepo = new UserRepository($db, $logger);
$controller = new UserController($userRepo);
```

With a container:
```php
// Container builds the whole tree automatically
$controller = $app->make(UserController::class);
```

**Laravel equivalent:** `Illuminate\Container\Container` (~1857 lines → we reduce to ~200)

---

## 2. 🧠 Concept (WHAT)

The container holds three types of registrations:

| Type | Method | Behavior |
|------|--------|----------|
| **Binding** | `bind()` | New instance each time |
| **Singleton** | `singleton()` | Same instance every call |
| **Instance** | `instance()` | Pre-built object stored directly |

**Auto-resolution** (reflection): If a class isn't bound, the container inspects its constructor via PHP's `ReflectionClass` and recursively resolves each parameter.

```
make(UserController::class)
  → __construct(UserRepository $repo)
    → make(UserRepository::class)
      → __construct(Database $db)
        → make(Database::class) ✓ found in bindings
```

---

## 3. 🏗 Implementation (HOW)

### File: `laravel-clone/src/Container/Container.php`

```php
<?php

namespace Framework\Container;

use Closure;
use ReflectionClass;
use ReflectionParameter;
use RuntimeException;

class Container
{
    /**
     * The globally available container instance.
     */
    protected static ?self $instance = null;

    /**
     * Registered bindings: abstract → ['concrete' => Closure, 'shared' => bool]
     */
    protected array $bindings = [];

    /**
     * Shared (singleton) instances already resolved.
     */
    protected array $instances = [];

    /**
     * Registered aliases: alias → abstract.
     */
    protected array $aliases = [];

    // ─── Registration ────────────────────────────────────────────────────────

    /**
     * Register a binding (new instance each call).
     *
     * @param string               $abstract  The key to bind under
     * @param Closure|string|null  $concrete  The factory or class name
     */
    public function bind(string $abstract, Closure|string|null $concrete = null): void
    {
        // If no concrete given, the abstract IS the concrete (auto-resolve)
        if ($concrete === null) {
            $concrete = $abstract;
        }

        // Wrap class strings in a closure so resolution is uniform
        if (is_string($concrete)) {
            $concrete = $this->wrapClass($abstract, $concrete);
        }

        $this->bindings[$abstract] = [
            'concrete' => $concrete,
            'shared'   => false,
        ];
    }

    /**
     * Register a singleton (same instance every call).
     */
    public function singleton(string $abstract, Closure|string|null $concrete = null): void
    {
        if ($concrete === null) {
            $concrete = $abstract;
        }

        if (is_string($concrete)) {
            $concrete = $this->wrapClass($abstract, $concrete);
        }

        $this->bindings[$abstract] = [
            'concrete' => $concrete,
            'shared'   => true,
        ];
    }

    /**
     * Register an already-built object as a singleton.
     */
    public function instance(string $abstract, mixed $instance): mixed
    {
        $this->instances[$abstract] = $instance;

        return $instance;
    }

    /**
     * Alias one abstract to another.
     */
    public function alias(string $abstract, string $alias): void
    {
        $this->aliases[$alias] = $abstract;
    }

    // ─── Resolution ──────────────────────────────────────────────────────────

    /**
     * Resolve a binding from the container.
     *
     * This is the main method. Everything flows through here.
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        // Resolve aliases first
        $abstract = $this->getAlias($abstract);

        // Return shared instance if already resolved
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // Get the concrete factory
        $concrete = $this->getConcrete($abstract);

        // Build the object
        $object = $this->build($concrete, $parameters);

        // If singleton, store it for next call
        if ($this->isShared($abstract)) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    /**
     * Build a concrete (Closure or class name) into an object.
     */
    protected function build(Closure|string $concrete, array $parameters = []): mixed
    {
        // If it's a Closure, just call it
        if ($concrete instanceof Closure) {
            return $concrete($this, $parameters);
        }

        // Use reflection to auto-resolve the class constructor
        try {
            $reflector = new ReflectionClass($concrete);
        } catch (\ReflectionException $e) {
            throw new RuntimeException("Cannot resolve class [{$concrete}]: " . $e->getMessage());
        }

        if (! $reflector->isInstantiable()) {
            throw new RuntimeException("Class [{$concrete}] is not instantiable (abstract or interface).");
        }

        $constructor = $reflector->getConstructor();

        // No constructor — just new it up
        if ($constructor === null) {
            return new $concrete;
        }

        // Resolve each constructor parameter
        $deps = $this->resolveParameters($constructor->getParameters(), $parameters);

        return $reflector->newInstanceArgs($deps);
    }

    /**
     * Resolve constructor parameters using the container and overrides.
     *
     * @param ReflectionParameter[] $parameters
     */
    protected function resolveParameters(array $parameters, array $overrides = []): array
    {
        $resolved = [];

        foreach ($parameters as $param) {
            $name = $param->getName();

            // 1. Check for explicit override
            if (array_key_exists($name, $overrides)) {
                $resolved[] = $overrides[$name];
                continue;
            }

            // 2. Try to resolve from type hint
            $type = $param->getType();

            if ($type && ! $type->isBuiltin()) {
                $resolved[] = $this->make($type->getName());
                continue;
            }

            // 3. Fall back to default value
            if ($param->isDefaultValueAvailable()) {
                $resolved[] = $param->getDefaultValue();
                continue;
            }

            throw new RuntimeException(
                "Cannot resolve parameter [{$name}] — no type hint, binding, or default."
            );
        }

        return $resolved;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Wrap a class name in a Closure for uniform resolution.
     */
    protected function wrapClass(string $abstract, string $concrete): Closure
    {
        return function (self $container, array $parameters = []) use ($abstract, $concrete) {
            // If abstract === concrete, build directly to avoid infinite loop
            if ($abstract === $concrete) {
                return $container->build($concrete, $parameters);
            }

            return $container->make($concrete, $parameters);
        };
    }

    protected function getConcrete(string $abstract): Closure|string
    {
        if (isset($this->bindings[$abstract])) {
            return $this->bindings[$abstract]['concrete'];
        }

        // Not bound — auto-resolve by class name
        return $abstract;
    }

    protected function isShared(string $abstract): bool
    {
        return isset($this->bindings[$abstract]['shared'])
            && $this->bindings[$abstract]['shared'] === true;
    }

    protected function getAlias(string $abstract): string
    {
        return $this->aliases[$abstract] ?? $abstract;
    }

    /**
     * Check if something is bound.
     */
    public function bound(string $abstract): bool
    {
        $abstract = $this->getAlias($abstract);

        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]);
    }

    /**
     * Call a callable, injecting its dependencies.
     */
    public function call(callable $callback, array $parameters = []): mixed
    {
        $reflection = new \ReflectionFunction(
            $callback instanceof Closure ? $callback : Closure::fromCallable($callback)
        );

        $deps = $this->resolveParameters($reflection->getParameters(), $parameters);

        return $callback(...$deps);
    }

    // ─── Global Instance (Singleton pattern) ─────────────────────────────────

    public static function getInstance(): static
    {
        if (static::$instance === null) {
            static::$instance = new static;
        }

        return static::$instance;
    }

    public static function setInstance(?self $container): void
    {
        static::$instance = $container;
    }

    // ─── ArrayAccess-style sugar ──────────────────────────────────────────────

    public function offsetGet(string $abstract): mixed
    {
        return $this->make($abstract);
    }

    public function offsetSet(string $abstract, mixed $value): void
    {
        if ($value instanceof Closure) {
            $this->bind($abstract, $value);
        } else {
            $this->instance($abstract, $value);
        }
    }

    public function __get(string $key): mixed
    {
        return $this->make($key);
    }
}
```

---

## 4. 🔗 Integration

`Application` (Step 2) **extends** this `Container`. So when you call `$app->make(...)`, you're calling `Container::make()`.

The `public/index.php` already calls:
```php
$kernel = $app->make(\Framework\Http\Kernel::class);
```

This works because `$app` is a `Container` — it resolves `Kernel` and recursively resolves its dependencies.

---

## 5. ✅ Usage Example

```php
$container = new \Framework\Container\Container();

// 1. Bind a simple class
$container->bind('logger', \App\Services\Logger::class);
$logger1 = $container->make('logger'); // new instance
$logger2 = $container->make('logger'); // another new instance

// 2. Singleton — same instance
$container->singleton('config', \Framework\Config\Repository::class);
$c1 = $container->make('config');
$c2 = $container->make('config');
assert($c1 === $c2); // true

// 3. Store a pre-built instance
$container->instance('request', new \Framework\Http\Request(...));

// 4. Auto-resolution (no binding needed)
class UserController {
    public function __construct(
        private UserRepository $users  // Container resolves this automatically
    ) {}
}
$ctrl = $container->make(UserController::class);

// 5. Closure binding
$container->bind('db', function ($app) {
    return new PDO('sqlite:' . $app->storagePath('db.sqlite'));
});
```

---

## 6. 📌 Key Elements

| Element | Purpose |
|---------|---------|
| `$bindings` | Stores `[abstract => [concrete, shared]]` |
| `$instances` | Cache for singletons |
| `bind()` | New instance per call |
| `singleton()` | One instance forever |
| `instance()` | Store a pre-built object |
| `make()` | The main resolution entry point |
| `build()` | Uses `ReflectionClass` to auto-wire |
| `resolveParameters()` | Recursively resolves constructor deps |
| `call()` | Calls a callable with injected args |

---

## 7. ⚠️ Simplifications Made

| Laravel | Our Clone | Reason |
|---------|-----------|--------|
| `Container.php` has 1857 lines | ~200 lines | Removed: contextual bindings, tags, extenders, rebound callbacks, attribute bindings |
| `ArrayAccess` full implementation | `__get` + `offsetGet`/`offsetSet` | Laravel implements `ArrayAccess` interface fully |
| Contextual bindings (`when()->needs()->give()`) | Not implemented | Advanced feature, not core |
| `bindMethod()` for `Class@method` | Not implemented | Extra indirection |
| `scoped()` bindings | Not implemented | Request-scoped singletons — skip for now |
| Circular dependency detection | Not implemented | Adds complexity, rare in practice |

---

**Next:** [Step 04 — Service Providers →](./04-service-providers.md)
