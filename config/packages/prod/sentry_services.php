<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Monolog\Level;
use Sentry\Monolog\BreadcrumbHandler;
use Sentry\State\HubInterface;

return App::config([
    'services' => [
        BreadcrumbHandler::class => [
            'arguments' => [
                '$hub' => '@' . HubInterface::class,
                '$level' => Level::Info,
            ],
        ],
    ],
]);
