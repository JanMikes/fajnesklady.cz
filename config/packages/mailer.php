<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        'mailer' => [
            'dsn' => '%env(MAILER_DSN)%',
            'envelope' => [
                'sender' => '%env(MAILER_FROM_EMAIL)%',
            ],
            'headers' => [
                'from' => '%env(MAILER_FROM_NAME)% <%env(MAILER_FROM_EMAIL)%>',
            ],
        ],
    ],
]);
