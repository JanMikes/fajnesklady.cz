<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        'router' => [
            'default_uri' => '%env(DEFAULT_URI)%',
        ],
    ],
    'when@prod' => [
        'framework' => [
            'router' => [
                'strict_requirements' => null,
            ],
        ],
    ],
]);
