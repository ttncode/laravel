<?php

namespace Framework\Foundation\Http;

use Framework\Contracts\Http\Kernel as KernelContract;
use Framework\Foundation\Application;
use Framework\Foundation\Configuration\Middleware;
use Framework\Http\Response;
use Framework\Pipeline\Pipeline;
use Framework\Routing\Router;

class Kernel implements KernelContract
{
    /**
     * The bootstrap classes for the application.
     *
     * @var string[]
     */
    protected array $bootstrappers = [
        \Framework\Foundation\Bootstrap\RegisterProviders::class,
        \Framework\Foundation\Bootstrap\BootProviders::class,
    ];

    public function __construct(
        protected Application $app,
        protected Router $router,
    ) {}

    /**
     * Bootstrap the application for HTTP requests.
     *
     * @return void
     */
    public function bootstrap(): void
    {
        if (! $this->app->hasBeenBootstrapped()) {
            $this->app->bootstrapWith($this->bootstrappers);
        }
    }

    /**
     * Handle the incoming HTTP request.
     *
     * @param \Framework\Http\Request $request
     * @return \Framework\Http\Response
     */
    public function handle($request): Response
    {
        try {
            $this->bootstrap();

            /** @var Middleware $middleware */
            $middleware = $this->app->make(Middleware::class);
            $globalMiddleware = $middleware->getGlobalMiddleware();

            // Run the request through the pipeline
            return (new Pipeline($this->app))
                ->send($request)
                ->through($globalMiddleware)
                ->then($this->dispatchToRouter());
        } catch (\Throwable $th) {
            return new Response('Server error: ' . $th->getMessage(), 500);
        }
    }

    /**
     * Dispatch the request to the router.
     *
     * @return \Closure
     */
    protected function dispatchToRouter(): \Closure
    {
        return function ($request) {
            return $this->router->dispatch($request);
        };
    }

    /**
     * Perform any final actions for the request lifecycle.
     *
     * @param  \Framework\Http\Request  $request
     * @param  \Framework\Http\Response  $response
     * @return void
     */
    public function terminate($request, $response): void
    {
        // TODO: Implement closing database connections, writing session data.
    }

    /**
     * Get the Laravel application instance.
     *
     * @return \Framework\Foundation\Application
     */
    public function getApplication(): Application
    {
        return $this->app;
    }
}
