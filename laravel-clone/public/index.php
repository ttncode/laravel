<?php

use Framework\Foundation\Application;
use Framework\Http\Request;

// Register the Composer autoload...[  p;0-]]"

require_once __DIR__ . '/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__ . '/../bootstrap/app.php';

$app->handleRequest(Request::capture());
