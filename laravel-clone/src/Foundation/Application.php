<?php

namespace Framework\Foundation;

use App\Providers\RoutingServiceProvider;
use Framework\Container\Container;
use Framework\Contracts\Http\Kernel as HttpKernelContract;
use Framework\Http\Request;

class Application extends Container
{
    /**
     * The Laravel framework version.
     *
     * @var string
     */
    const VERSION = '13.7.0';

    /**
     * The base path for the Laravel installation.
     *
     * @var string
     */
    protected $basePath;

    /**
     * Indicates if the application has been bootstrapped before.
     *
     * @var bool
     */
    protected $hasBeenBootstrapped = false;

    /**
     * Indicates if the application has "booted".
     *
     * @var bool
     */
    protected $booted = false;

    /**
     * All of the registered service providers.
     *
     * @var array<string, \Framework\Support\ServiceProvider>
     */
    protected $serviceProviders = [];

    /**
     * The array of booting callbacks.
     *
     * @var callable[]
     */
    protected $bootingCallbacks = [];

    /**
     * The array of booted callbacks.
     *
     * @var callable[]
     */
    protected $bootedCallbacks = [];

    /**
     * Create a new application instance.
     *
     * @param  string|null  $basePath
     */
    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');

        $this->registerBaseBindings();
        $this->registerBaseServiceProviders();
    }

    /**
     * Begin configuring a new Laravel application instance.
     *
     * @param  string  $basePath
     * @return Configuration\ApplicationBuilder
     */
    public static function configure(string $basePath)
    {
        return (new Configuration\ApplicationBuilder(new static($basePath)))
            ->withKernels();
    }

    /**
     * Register the basic bindings into the container.
     *
     * @return void
     */
    protected function registerBaseBindings()
    {
        self::setInstance($this);

        $this->instance('app', $this);
        $this->instance(Container::class, $this);
        $this->instance(Application::class, $this);
    }

    /**
     * Register the basic service providers.
     *
     * @return void
     */
    protected function registerBaseServiceProviders(): void
    {
        $this->register(new RoutingServiceProvider($this));
    }

    /**
     * Register a service provider with the application.
     *
     * @param  \Framework\Support\ServiceProvider|string  $provider
     * @return \Framework\Support\ServiceProvider
     */
    protected function register($provider)
    {
        if (is_string($provider)) {
            $provider = new $provider($this);
        }

        /** @var \App\Providers\AppServiceProvider $provider */
        $provider->register();

        $this->serviceProviders[] = $provider;
    }

    /**
     * Register a service provider with the application.
     *
     * @param  \Framework\Support\ServiceProvider $provider
     * @return void
     */
    public function registerProvider(\Framework\Support\ServiceProvider $provider): void
    {
        $this->serviceProviders[] = $provider;
    }


    # ═══════════════════════════════════════════════════════════════════════════
    # Lifecycle
    # ═══════════════════════════════════════════════════════════════════════════
    /**
     * Run the given array of bootstrap classes.
     *
     * @param  string[]  $bootstrappers
     * @return void
     */
    public function bootstrapWith(array $bootstrappers): void
    {
        $this->hasBeenBootstrapped = true;

        foreach ($bootstrappers as $bootstrapper) {
            $this->make($bootstrapper)->bootstrap($this);
        }
    }

    /**
     * Boot the application's service providers.
     *
     * @return void
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ($this->bootingCallbacks as $callback) {
            $callback($this);
        }

        foreach ($this->serviceProviders as $provider) {
            if (method_exists($provider, 'boot')) {
                $provider->boot();
            }
        }

        $this->booted = true;

        foreach ($this->bootedCallbacks as $callback) {
            $callback($this);
        }
    }

    /**
     * Register a new boot listener.
     * 
     * @param  callable  $callback
     * @return void
     */
    public function booting(callable $callback)
    {
        $this->bootingCallbacks[] = $callback;
    }

    /**
     * Register a new booted listener.
     * 
     * @param callable $callback
     * @return void
     */
    public function booted(callable $callback)
    {
        $this->bootedCallbacks[] = $callback;
    }

    /**
     * Determine if the application has been bootstrapped before.
     *
     * @return bool
     */
    public function hasBeenBootstrapped()
    {
        return $this->hasBeenBootstrapped;
    }

    /**
     * Determine if the application has booted.
     *
     * @return bool
     */
    public function isBooted()
    {
        return $this->booted;
    }


    # ═══════════════════════════════════════════════════════════════════════════
    # Path Helpers
    # ═══════════════════════════════════════════════════════════════════════════
    /**
     * Get the base path of the Laravel installation.
     */
    public function basePath(string $path = ''): string
    {
        return $this->joinPath($this->basePath, $path);
    }

    /**
     * Get the path to the application "app" directory.
     */
    public function appPath(string $path = ''): string
    {
        return $this->joinPath($this->basePath . '/app', $path);
    }

    /**
     * Get the path to the application configuration files. 
     */
    public function configPath(string $path = ''): string
    {
        return $this->joinPath($this->basePath . '/config', $path);
    }

    /**
     * Get the path to the public / web directory.
     */
    public function publicPath(string $path = ''): string
    {
        return $this->joinPath($this->basePath . '/public', $path);
    }

    /**
     * Get the path to the resources directory.
     */
    public function resourcePath(string $path = ''): string
    {
        return $this->joinPath($this->basePath . '/resources', $path);
    }

    /**
     * Get the path to the routes directory.
     */
    public function routesPath(string $path = ''): string
    {
        return $this->joinPath($this->basePath . '/routes', $path);
    }

    /**
     * Get the path to the storage directory.
     */
    public function storagePath(string $path = ''): string
    {
        return $this->joinPath($this->basePath . '/storage', $path);
    }

    /**
     * Join the given paths together.
     */
    protected function joinPath(string $base, string $path): string
    {
        return $path ? $base . DIRECTORY_SEPARATOR . ltrim($path, '/\\') : $base;
    }

    /**
     * Handle the incoming HTTP request and send the response to the browser.
     *
     * @param  \Framework\Http\Request  $request
     * @return void
     */
    public function handleRequest(Request $request)
    {
        /** @var HttpKernelContract $kernel */
        $kernel = $this->make(HttpKernelContract::class);

        $response = $kernel->handle($request)->send();

        $kernel->terminate($request, $response);
    }
}
