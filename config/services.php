<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'services' => [
        '_defaults' => [
            'autowire' => true,
            'autoconfigure' => true,
        ],
        'App\\' => [
            'resource' => '../src/',
            'exclude' => [
                '../src/DependencyInjection/',
                '../src/Entity/',
                '../src/Kernel.php',
                '../src/Command/*Command.php',
                '../src/Query/*Query.php',
                '../src/Query/*Result.php',
                '../src/Event/*.php',
            ],
        ],
    ],
    'when@test' => [
        'services' => [
            '_defaults' => [
                'autowire' => true,
                'autoconfigure' => true,
                'public' => true,
            ],
            'test.service_container' => [
                'alias' => 'service_container',
                'public' => true,
            ],
            'App\\' => [
                'resource' => '../src/',
                'exclude' => [
                    '../src/DependencyInjection/',
                    '../src/Entity/',
                    '../src/Kernel.php',
                    '../src/Command/*Command.php',
                    '../src/Query/*Query.php',
                    '../src/Query/*Result.php',
                    '../src/Event/*.php',
                ],
                'public' => true,
            ],
        ],
    ],
]);
