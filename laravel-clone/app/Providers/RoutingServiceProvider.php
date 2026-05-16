<?php

namespace App\Providers;

use Framework\Routing\Router;
use Framework\Support\ServiceProvider;

class RoutingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Router::class, Router::class);
    }

    public function boot(): void
    {
        echo '<h4>[RoutingServiceProvide] - Booting routes...</h4>';

        $routingConfig = $this->app->make('config.routing');

        if (isset($routingConfig['web']) && file_exists($routingConfig['web'])) {
            $router = $this->app->make(Router::class);

            require $routingConfig['web'];
        }
    }
}
