<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'doctrine' => [
        'dbal' => [
            'url' => '%env(resolve:DATABASE_URL)%',
            'profiling_collect_backtrace' => '%kernel.debug%',
        ],
        'orm' => [
            'naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware',
            'identity_generation_preferences' => [
                'Doctrine\\DBAL\\Platforms\\PostgreSQLPlatform' => 'identity',
            ],
            'auto_mapping' => true,
            'mappings' => [
                'App' => [
                    'type' => 'attribute',
                    'is_bundle' => false,
                    'dir' => '%kernel.project_dir%/src',
                    'prefix' => 'App',
                    'alias' => 'App',
                ],
            ],
        ],
    ],
]);
