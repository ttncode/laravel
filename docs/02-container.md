# Step 02: IoC Container

---

## 🚩 The Problem

Consider a realistic controller:

```php
class UserController
{
    public function __construct(
        private UserRepository $users,
        private Logger $logger,
        private Mailer $mailer
    ) {}
}
```

`UserRepository` itself needs a database connection. `Logger` needs a file path. `Mailer` needs SMTP credentials. To create a `UserController`, you must first build all its dependencies — and their dependencies.

Done manually, that looks like this:

```php
$config = new Config('/path/to/config.php');
$db     = new Database($config->get('db.host'), $config->get('db.name'));
$logger = new Logger($config->get('log.path'));
$smtp   = new SmtpConnection($config->get('mail.host'), $config->get('mail.port'));
$mailer = new Mailer($smtp);
$repo   = new UserRepository($db);
$ctrl   = new UserController($repo, $logger, $mailer);
```

This is six lines of boilerplate before the actual work begins. In a large application with dozens of controllers and services, this becomes a maintenance nightmare.

But there is a second, more subtle problem.

---

## 🔍 Why Manual Wiring Fails at Scale

**Problem 1: Repetition and brittleness**

Every place that needs a `UserController` must reproduce all six wiring lines. If `UserRepository` gains a new constructor parameter, you update it in every location where it's constructed.

**Problem 2: Expensive objects rebuilt on every request**

Your database connection, config reader, and logger are the same every time — but with manual wiring, you rebuild them from scratch on every request. This is wasteful.

Consider 500 concurrent requests hitting your server. With manual wiring, 500 database connections open simultaneously, even if you only need 10. With a container, you register the database connection once as a singleton — every request in the same PHP process reuses the same object.
app
> This is the example you noted: caching initialized services in a container to avoid rebuilding them on every request. That's exactly what `singleton()` does.

**Problem 3: Can't swap implementations**

In testing, you want a fake database, not the real one. With manual wiring scattered everywhere, swapping an implementation means touching every wiring site. With a container, you change one binding: `bind(Database::class, FakeDatabase::class)`.

---

## 💡 The Solution: IoC Container

**Inversion of Control** means: instead of code creating its dependencies, something else creates them and passes them in. The container is that "something else."

The container maintains a registry of **how to build things**. When you ask it for an object, it builds it (and all its dependencies) for you:

```php
// Register: "when someone asks for UserController, here is how to build it"
$container->singleton(UserController::class, UserController::class);

// Resolve: container builds UserController + all its dependencies automatically
$ctrl = $container->make(UserController::class);
```

Three registration types:

| Method | Behavior | When to use |
|--------|----------|-------------|
| `bind()` | New instance every call | Stateless objects |
| `singleton()` | One instance, cached | DB, config, logger — expensive or shared state |
| `instance()` | Store a pre-built object | Objects you built before the container existed |

**Auto-resolution via Reflection**: if a class isn't explicitly registered, the container uses PHP's `ReflectionClass` to inspect its constructor and recursively resolves each type-hinted parameter. This means many classes need zero explicit registration — the container figures them out.

---

## 🏗 Implementation

Create the directory and file:

```bash
mkdir -p src/Container
touch src/Container/Container.php
```

### File: `src/Container/Container.php`

```php
<?php

namespace Framework\Container;

use Closure;
use ReflectionClass;
use RuntimeException;

class Container
{
    /**
     * The globally available container instance.
     * Allows `Container::getInstance()` from anywhere in the framework.
     */
    protected static ?self $instance = null;

    /**
     * Registered bindings.
     * Structure: [ abstract => ['concrete' => Closure, 'shared' => bool] ]
     */
    protected array $bindings = [];

    /**
     * Resolved singleton instances.
     * Once built, singletons live here for the process lifetime.
     */
    protected array $instances = [];

    /**
     * Aliases: maps a short name to an abstract.
     * e.g., 'router' => Router::class
     */
    protected array $aliases = [];

    // ─── Registration ─────────────────────────────────────────────────────────

    /**
     * Register a binding: new instance on every make() call.
     *
     * @param string               $abstract  The key to bind under (usually a class/interface name)
     * @param Closure|string|null  $concrete  A factory closure or class name. Defaults to $abstract.
     */
    public function bind(string $abstract, Closure|string|null $concrete = null): void
    {
        $this->addBinding($abstract, $concrete, shared: false);
    }

    /**
     * Register a singleton: built once, then cached and returned on every make() call.
     *
     * This is how expensive services (DB connections, config, logger) are shared
     * across all code in the same process without being rebuilt.
     */
    public function singleton(string $abstract, Closure|string|null $concrete = null): void
    {
        $this->addBinding($abstract, $concrete, shared: true);
    }

    /**
     * Store a pre-built object directly as a singleton.
     * Subsequent make() calls return this exact object.
     */
    public function instance(string $abstract, mixed $instance): mixed
    {
        $this->instances[$abstract] = $instance;
        return $instance;
    }

    /**
     * Register an alias so that a short name resolves to an abstract.
     * e.g., alias(Router::class, 'router') lets you do make('router').
     */
    public function alias(string $abstract, string $alias): void
    {
        $this->aliases[$alias] = $abstract;
    }

    // ─── Resolution ───────────────────────────────────────────────────────────

    /**
     * Resolve an abstract from the container.
     *
     * Resolution order:
     * 1. Check aliases → resolve the real abstract name
     * 2. Return cached singleton if already built
     * 3. Find the concrete factory (or use the abstract as the class name)
     * 4. Build the object
     * 5. Cache if it's a singleton
     *
     * @param array $parameters  Optional overrides for constructor parameters (by name)
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        $abstract = $this->getAlias($abstract);

        // Return cached singleton immediately
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        $concrete = $this->getConcrete($abstract);
        $object   = $this->build($concrete, $parameters);

        // Cache singletons so they are never rebuilt
        if ($this->isShared($abstract)) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    /**
     * Build a concrete into an object.
     *
     * If it's a Closure: call it with the container and parameters.
     * If it's a class name: use ReflectionClass to auto-resolve the constructor.
     */
    protected function build(Closure|string $concrete, array $parameters = []): mixed
    {
        if ($concrete instanceof Closure) {
            return $concrete($this, $parameters);
        }

        try {
            $reflector = new ReflectionClass($concrete);
        } catch (\ReflectionException $e) {
            throw new RuntimeException("Cannot build [{$concrete}]: class does not exist.");
        }

        if (! $reflector->isInstantiable()) {
            throw new RuntimeException(
                "[{$concrete}] is not instantiable. Is it an abstract class or interface?"
            );
        }

        $constructor = $reflector->getConstructor();

        // No constructor — just instantiate directly
        if ($constructor === null) {
            return new $concrete;
        }

        $dependencies = $this->resolveDependencies(
            $constructor->getParameters(),
            $parameters
        );

        return $reflector->newInstanceArgs($dependencies);
    }

    /**
     * Resolve constructor parameters using type hints and the container.
     *
     * For each parameter:
     * 1. If an explicit override is provided by name, use it
     * 2. If it has a class/interface type hint, recursively make() it
     * 3. If it has a default value, use the default
     * 4. Otherwise, throw — we can't resolve it
     *
     * @param \ReflectionParameter[] $parameters
     */
    protected function resolveDependencies(array $parameters, array $overrides = []): array
    {
        $resolved = [];

        foreach ($parameters as $param) {
            $name = $param->getName();

            // 1. Explicit override by parameter name
            if (array_key_exists($name, $overrides)) {
                $resolved[] = $overrides[$name];
                continue;
            }

            // 2. Resolve by type hint
            $type = $param->getType();

            if ($type instanceof \ReflectionNamedType && ! $type->isBuiltin()) {
                $resolved[] = $this->make($type->getName());
                continue;
            }

            // 3. Use default value
            if ($param->isDefaultValueAvailable()) {
                $resolved[] = $param->getDefaultValue();
                continue;
            }

            throw new RuntimeException(
                "Cannot resolve parameter [\${$name}] of [{$param->getDeclaringClass()?->getName()}]."
                . " No type hint, binding, or default value."
            );
        }

        return $resolved;
    }

    /**
     * Call a callable, injecting its dependencies from the container.
     *
     * Works with: closures, [object, 'method'], static methods.
     * Use $parameters to pass primitives (strings, ints) by parameter name.
     */
    public function call(callable $callback, array $parameters = []): mixed
    {
        if ($callback instanceof Closure) {
            $reflection = new \ReflectionFunction($callback);
        } elseif (is_array($callback)) {
            $reflection = new \ReflectionMethod($callback[0], $callback[1]);
        } else {
            $reflection = new \ReflectionFunction(\Closure::fromCallable($callback));
        }

        $deps = $this->resolveDependencies($reflection->getParameters(), $parameters);

        return $callback(...$deps);
    }

    // ─── Introspection ────────────────────────────────────────────────────────

    /**
     * Check if an abstract is bound (as binding, instance, or alias).
     */
    public function bound(string $abstract): bool
    {
        $abstract = $this->getAlias($abstract);
        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]);
    }

    // ─── Internal Helpers ─────────────────────────────────────────────────────

    protected function addBinding(string $abstract, Closure|string|null $concrete, bool $shared): void
    {
        if ($concrete === null) {
            $concrete = $abstract;
        }

        // Wrap class name strings in a closure so all resolution goes through build()
        if (is_string($concrete)) {
            $concrete = $this->wrapClass($abstract, $concrete);
        }

        $this->bindings[$abstract] = ['concrete' => $concrete, 'shared' => $shared];
    }

    /**
     * Wrap a class name in a closure to normalize all concretes to Closures.
     */
    protected function wrapClass(string $abstract, string $concrete): Closure
    {
        return function (self $container, array $parameters = []) use ($abstract, $concrete): mixed {
            // Avoid infinite loop: if abstract === concrete, build directly
            if ($abstract === $concrete) {
                return $container->build($concrete, $parameters);
            }
            return $container->make($concrete, $parameters);
        };
    }

    protected function getConcrete(string $abstract): Closure|string
    {
        return $this->bindings[$abstract]['concrete'] ?? $abstract;
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

    // ─── Global Instance ──────────────────────────────────────────────────────

    /**
     * Get the globally shared container instance.
     * The Application calls setInstance($this) in its constructor,
     * so this returns the Application after Step 03.
     */
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
}
```

---

## ✅ Verify

Create a temporary test script at the project root (delete it after):

```bash
touch verify_container.php
```

```php
<?php
// verify_container.php
require_once __DIR__ . '/vendor/autoload.php';

use Framework\Container\Container;

$container = new Container();

// ── Test 1: bind() gives a new instance every call ──
class SimpleLogger {}

$container->bind(SimpleLogger::class);
$a = $container->make(SimpleLogger::class);
$b = $container->make(SimpleLogger::class);
assert($a !== $b, 'bind() should return different instances');
echo "✓ bind() returns new instance each call\n";

// ── Test 2: singleton() caches the instance ──
$container->singleton('db', function () {
    return new \stdClass(); // imagine this is an expensive DB connection
});

$db1 = $container->make('db');
$db2 = $container->make('db');
assert($db1 === $db2, 'singleton() should return the same instance');
echo "✓ singleton() returns the same cached instance\n";

// ── Test 3: Auto-resolution via ReflectionClass ──
class Config {
    public string $env = 'local';
}

class App {
    public function __construct(public Config $config) {}
}

// No explicit binding — container inspects the constructor and resolves Config
$app = $container->make(App::class);
assert($app->config instanceof Config, 'auto-resolution should inject Config');
echo "✓ Auto-resolution injects dependencies without explicit binding\n";

echo "\nAll checks passed.\n";
```

Run it:

```bash
php verify_container.php
```

Expected output:
```
✓ bind() returns new instance each call
✓ singleton() returns the same cached instance
✓ Auto-resolution injects dependencies without explicit binding

All checks passed.
```

Clean up:

```bash
rm verify_container.php
```

---

## 📌 What We Built

| Element | Purpose |
|---------|---------|
| `$bindings` | Registry of how to build each abstract |
| `$instances` | Cache for singleton instances (persists for process lifetime) |
| `bind()` | Register a transient (new instance per call) |
| `singleton()` | Register a cached service (built once, reused) |
| `instance()` | Store a pre-built object directly |
| `make()` | The main resolution entry point |
| `build()` | Uses `ReflectionClass` to instantiate with auto-wired dependencies |
| `resolveDependencies()` | Recursively resolves constructor parameters |
| `call()` | Calls any callable with injected dependencies |
| `setInstance()` / `getInstance()` | Global access point (used by Application) |

**Directory structure after this step:**

```
laravel-clone/
├── composer.json
├── composer.lock
├── vendor/
├── public/
│   └── index.php
└── src/
    └── Container/
        └── Container.php    ← NEW
```

---

## ⚠️ Simplifications vs Laravel

| Laravel's Container | Our Implementation | Reason |
|--------------------|--------------------|--------|
| ~1,900 lines | ~200 lines | Removed: contextual bindings, tags, extenders, rebound callbacks, attribute-based injection (PHP 8) |
| Contextual binding: `when(A)->needs(B)->give(C)` | Not implemented | Advanced feature — teaches core concepts first |
| `scoped()` bindings (reset per request in Octane) | Not implemented | Requires long-running server context |
| Circular dependency detection | Not implemented | Adds complexity; rare in practice |
| Full `ArrayAccess` implementation | Not implemented | Use `make()` directly |
| PHP 8 attribute-based injection | Not implemented | Too framework-specific |

---

**Next:** [Step 03 — Application →](./03-application.md)
