<?php

namespace App\Http\Middleware;

use Framework\Http\Request;
use Framework\Http\Response;

class AuthenticationMiddleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        echo "<h4>[Authentication Middleware] - Passed through.</h4>";

        /** @var Response $response */
        $response = $next($request);

        echo '<h4>[Authentication Middleware] - Finishing the request.</h4>';

        return $response;
    }
}
