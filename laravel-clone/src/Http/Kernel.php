<?php

namespace Framework\Http;

use Framework\Foundation\Application;

class Kernel
{
    public function __construct(protected Application $app) {}

    public function handle(Request $request): Response
    {
        return new Response('Kernel is handling the request!');
    }
}
