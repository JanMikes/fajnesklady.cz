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
                '../src/Query/*Result.php',
                '../src/Event/*.php',
            ],
        ],
        'App\\Identity\\ProvideIdentity' => [
            'alias' => 'App\\Identity\\RandomIdentityProvider',
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
                    '../src/Query/*Result.php',
                    '../src/Event/*.php',
                ],
                'public' => true,
            ],
            'App\\Tests\\Support\\PredictableIdentityProvider' => [
                'tags' => [['name' => 'kernel.reset', 'method' => 'reset']],
            ],
            'App\\Identity\\ProvideIdentity' => [
                'alias' => 'App\\Tests\\Support\\PredictableIdentityProvider',
            ],
        ],
    ],
]);
