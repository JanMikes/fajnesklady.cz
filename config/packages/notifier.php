<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        'notifier' => [
            'chatter_transports' => [],
            'texter_transports' => [],
            'channel_policy' => [
                'urgent' => ['email'],
                'high' => ['email'],
                'medium' => ['email'],
                'low' => ['email'],
            ],
            'admin_recipients' => [
                ['email' => 'admin@example.com'],
            ],
        ],
    ],
]);
