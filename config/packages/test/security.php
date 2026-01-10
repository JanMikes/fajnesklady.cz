<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'security' => [
        'password_hashers' => [
            'Symfony\\Component\\Security\\Core\\User\\PasswordAuthenticatedUserInterface' => [
                'algorithm' => 'auto',
                'cost' => 4,
                'time_cost' => 3,
                'memory_cost' => 10,
            ],
        ],
    ],
]);
