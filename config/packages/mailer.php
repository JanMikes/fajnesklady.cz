<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        'mailer' => [
            'dsn' => '%env(MAILER_DSN)%',
            'envelope' => [
                'sender' => 'noreply@fajnesklady.cz',
            ],
            'headers' => [
                'from' => 'Fajné Sklady <noreply@fajnesklady.cz>',
            ],
        ],
    ],
]);
