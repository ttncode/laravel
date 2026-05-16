<?php

namespace Framework\Foundation\Bootstrap;

use Framework\Foundation\Application;

class BootProviders
{
    public function bootstrap(Application $app): void
    {
        echo '<h4>[BootProviders] - Starting to boot service providers.</h4>';

        $app->boot();
    }
}
