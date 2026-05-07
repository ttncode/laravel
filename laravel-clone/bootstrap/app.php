<?php

// Create and configure the Application
$app = new \Framework\Foundation\Application(
    basePath: dirname(__DIR__)
);

// Register the HTTP Kernel binding
$app->singleton(
    \Framework\Http\Kernel::class,
    \Framework\Http\Kernel::class
);

return $app;
