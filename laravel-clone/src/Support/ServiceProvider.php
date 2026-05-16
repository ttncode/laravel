<?php

namespace Framework\Support;

abstract class ServiceProvider
{
    /**
     * The application instance.
     *
     * @var \Framework\Foundation\Application
     */
    protected $app;

    /**
     * Create a new service provider instance.
     *
     * @param  \Framework\Foundation\Application  $app
     */
    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        //
    }

    /**
     * Boot any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        //
    }
}
