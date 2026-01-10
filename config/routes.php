<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->import(
        resource: [
            'path' => '../src/Controller/',
            'namespace' => 'App\\Controller',
        ],
        type: 'attribute',
    );
};
