<?php

// Load the autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Boost the application
$app = require_once __DIR__ . '/../bootstrap/app.php';

// Handle the HTTP request
$kernel = $app->make(\Framework\Http\Kernel::class);
$request = \Framework\Http\Request::capture();
$response = $kernel->handle($request);

// Send back a response
$response->send();

// Kernel cleanup (terminate any middleware)
$kernel->terminate($request, $response);
