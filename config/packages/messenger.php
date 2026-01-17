<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        'messenger' => [
            'failure_transport' => 'failed',
            'transports' => [
                'async' => [
                    'dsn' => '%env(MESSENGER_TRANSPORT_DSN)%',
                    'options' => [
                        'use_notify' => true,
                        'check_delayed_interval' => 60000,
                    ],
                    'retry_strategy' => [
                        'max_retries' => 3,
                        'multiplier' => 2,
                    ],
                ],
                'failed' => 'doctrine://default?queue_name=failed',
            ],
            'default_bus' => 'command.bus',
            'buses' => [
                'command.bus' => [
                    'middleware' => [
                        'doctrine_transaction',
                        'validation',
                    ],
                ],
                'query.bus' => [
                    'middleware' => [
                        'validation',
                    ],
                ],
                'event.bus' => [
                    'default_middleware' => [
                        'enabled' => true,
                        'allow_no_handlers' => true,
                    ],
                    'middleware' => [
                        'doctrine_transaction',
                        'validation',
                    ],
                ],
            ],
            'routing' => [
                'Symfony\\Component\\Mailer\\Messenger\\SendEmailMessage' => 'async',
                'Symfony\\Component\\Notifier\\Message\\ChatMessage' => 'async',
                'Symfony\\Component\\Notifier\\Message\\SmsMessage' => 'async',
            ],
        ],
    ],
]);
