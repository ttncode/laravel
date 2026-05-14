<?php

namespace Framework\Foundation\Configuration;

use Framework\Foundation\Application;

class ApplicationBuilder
{
    protected array $routing = [];

    protected ?\Closure $middlewareCallback = null;

    protected ?\Closure $exceptionsCallback = null;

    /**
     * Create a new application builder instance.
     */
    public function __construct(protected Application $app) {}

    /**
     * Register the standard kernel classes for the application.
     *
     * @return $this
     */
    public function withKernels()
    {
        $this->app->singleton(
            \Framework\Http\Kernel::class,
            \Framework\Http\Kernel::class
        );

        return $this;
    }

    /**
     * Register the routing services for the application.
     *
     * @param string|null $web
     * @param string|null $api
     * @return static
     */
    public function withRouting(?string $web = null, ?string $api = null): static
    {
        if ($web) {
            $this->routing['web'] = $web;
        }

        if ($api) {
            $this->routing['api'] = $api;
        }

        return $this;
    }

    public function withMiddleware(callable $callback): static
    {
        $this->middlewareCallback = $callback;
        return $this;
    }

    public function withExceptions(callable $callback): static
    {
        $this->exceptionsCallback = $callback;
        return $this;
    }

    /**
     * Get the application instance.
     *
     * @return Application
     */
    public function create(): Application
    {
        // Store the routing paths in the application
        $this->app->instance('config.routing', $this->routing);

        // Process Middleware Configuration
        $middleware = new Middleware();
        if ($this->middlewareCallback) {
            call_user_func($this->middlewareCallback, $middleware);
        }
        $this->app->instance(Middleware::class, $middleware);

        // Process Exceptions Configuration
        $exceptions = new Exceptions();
        if ($this->exceptionsCallback) {
            call_user_func($this->exceptionsCallback, $exceptions);
        }
        $this->app->instance(Exceptions::class, $exceptions);

        return $this->app;
    }
}
