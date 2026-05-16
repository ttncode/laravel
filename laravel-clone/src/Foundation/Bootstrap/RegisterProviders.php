<?php

namespace Framework\Foundation\Bootstrap;

use Framework\Foundation\Application;

class RegisterProviders
{
    public function bootstrap(Application $app): void
    {
        echo '<h4>[RegisterProviders] - Starting to register service providers...</h4>'; 

        // In Laravel, this array is built dynamically from config/app.php
        // and composer.json package discovery. For simplicity, we hardcode it.
        $providers = [
            \App\Providers\AppServiceProvider::class
        ];

        foreach ($providers as $providerClass) {
            $provider = new $providerClass($app);
            $provider->register();

            // Store it in the app so we can boot it later
            $app->registerProvider($provider);
        }
    }
}
