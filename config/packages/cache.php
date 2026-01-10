<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        'cache' => [
            // Unique name of your app: used to compute stable namespaces for cache keys.
            // prefix_seed: your_vendor_name/app_name
        ],
    ],
]);
