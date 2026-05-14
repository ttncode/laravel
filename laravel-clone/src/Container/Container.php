<?php

namespace Framework\Container;

use Closure;
use ReflectionClass;
use RuntimeException;

class Container
{
    protected static ?self $instance = null;

    /**
     * Service bindings.
     */
    protected array $bindings = [];

    /**
     * Singleton instances.
     */
    protected array $instances = [];

    /**
     * Alias mappings.
     */
    protected array $aliases = [];


    # ═══════════════════════════════════════════════════════════════════════════
    # Singleton Instance
    # ═══════════════════════════════════════════════════════════════════════════
    public static function getInstance(): static
    {
        if (static::$instance === null) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    public static function setInstance(self $container): void
    {
        static::$instance = $container;
    }


    # ═══════════════════════════════════════════════════════════════════════════
    # Registration
    # ═══════════════════════════════════════════════════════════════════════════
    public function bind(string $abstract, Closure|string|null $concrete = null): void
    {
        $this->addBinding($abstract, $concrete, shared: false);
    }

    public function singleton(string $abstract, Closure|string|null $concrete = null): void
    {
        $this->addBinding($abstract, $concrete, shared: true);
    }

    public function instance(string $abstract, mixed $instance): mixed
    {
        $this->instances[$abstract] = $instance;
        return $instance;
    }

    public function alias(string $abstract, string $alias): void
    {
        if ($alias === $abstract) {
            throw new RuntimeException("[{$abstract}] is aliased to itself.");
        }
        $this->aliases[$alias] = $abstract;
    }


    # ═══════════════════════════════════════════════════════════════════════════
    # Resolution
    # ═══════════════════════════════════════════════════════════════════════════
    /**
     * Resolve an abstract from the container.
     * 
     * Resolution order:
     * 1. Resolve Alias
     * 2. Cache hit (Singleton)
     * 3. Get the Recipe (Concrete)
     * 4. Delegate to the Builder
     * 5. Save to Cache (Singleton)
     */
    public function make(string $abstract, array $params = []): mixed
    {
        $abstract = $this->getAlias($abstract);

        // Return cached singleton immediately
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        $concrete = $this->getConcrete($abstract);
        $object = $this->build($concrete, $params);

        if ($this->isShared($abstract)) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    /**
     * Build a concrete into an object.
     */
    public function build(Closure|string $concrete, array $params = []): mixed
    {
        // If a closure function is given, run it to build the object.
        if ($concrete instanceof Closure) {
            return $concrete($this, $params);
        }

        // If a class string is given, use reflection to build it
        try {
            $reflector = new ReflectionClass($concrete);
        } catch (\ReflectionException $e) {
            throw new RuntimeException("Cannot build [{$concrete}]: class does not exist.");
        }

        if (! $reflector->isInstantiable()) {
            throw new RuntimeException("[{$concrete}] is not instantiable. It is an abstract class or interface?");
        }

        $constructor = $reflector->getConstructor();

        // No constructor — just instantiate directly
        if (is_null($constructor)) {
            return new $concrete;
        }

        $dependencies = $this->resolveDependencies(
            $constructor->getParameters(),
            $params
        );

        return $reflector->newInstanceArgs($dependencies);
    }

    /**
     * Resolve construct parameters using type hints and the container.
     *
     * @param \ReflectionParameter[] $params
     */
    protected function resolveDependencies(array $params, array $overrides = []): array
    {
        $resolved = [];

        foreach ($params as $param) {
            $name = $param->getName();
            $type = $param->getType();

            // Explicit override by parameter name
            if (array_key_exists($name, $overrides)) {
                $resolved[] = $overrides[$name];
                continue;
            }

            // Resolve deeply dependency by type hint
            if ($type instanceof \ReflectionNamedType && ! $type->isBuiltin()) {
                $resolved[] = $this->make($type->getName());
                continue;
            }

            // Use default value
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


    # ═══════════════════════════════════════════════════════════════════════════
    # Internal Helpers
    # ═══════════════════════════════════════════════════════════════════════════
    protected function addBinding(string $abstract, Closure|string|null $concrete, bool $shared): void
    {
        // If no concrete is provided, use the abstract as the concrete
        if (is_null($concrete)) {
            $concrete = $abstract;
        }

        // If concrete is a string, wrap it in a closure that returns a new instance
        if (is_string($concrete)) {
            $concrete = $this->wrapClass($abstract, $concrete);
        }

        $this->bindings[$abstract] = compact('concrete', 'shared');
    }

    protected function wrapClass(string $abstract, string $concrete): Closure
    {
        return function (self $container, array $params = []) use ($abstract, $concrete) {
            // Avoid infinite loop: if abstract === concrete, build directly
            if ($abstract === $concrete) {
                return $container->build($concrete, $params);
            }
            return $container->make($concrete, $params);
        };
    }

    protected function isShared(string $abstract): bool
    {
        return isset($this->bindings[$abstract]['shared']) && $this->bindings[$abstract]['shared'] === true;
    }

    /**
     * Check if an abstract is bound to the container.
     */
    protected function bound(string $abstract): bool
    {
        $abstract = $this->getAlias($abstract);
        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]);
    }

    protected function getAlias(string $abstract): string
    {
        return $this->aliases[$abstract] ?? $abstract;
    }

    protected function getConcrete(string $abstract): Closure|string
    {
        return $this->bindings[$abstract]['concrete'] ?? $abstract;
    }
}
