<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        'default_locale' => 'en',
        'translator' => [
            'default_path' => '%kernel.project_dir%/translations',
            'providers' => [],
        ],
    ],
]);
