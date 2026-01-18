<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Sentry\Monolog\BreadcrumbHandler;

return App::config([
    'monolog' => [
        'handlers' => [
            // Sentry breadcrumb handler - captures INFO+ as breadcrumbs
            'sentry_breadcrumbs' => [
                'type' => 'service',
                'id' => BreadcrumbHandler::class,
                'channels' => ['!deprecation'],
            ],
            // Sentry error handler - sends ERROR+ to Sentry
            'sentry' => [
                'type' => 'sentry',
                'level' => 'error',
                'hub_id' => 'Sentry\\State\\HubInterface',
                'fill_extra_context' => true,
            ],
            // Existing handlers
            'main' => [
                'type' => 'fingers_crossed',
                'action_level' => 'error',
                'handler' => 'nested',
                'excluded_http_codes' => [404, 405],
                'channels' => ['!deprecation'],
                'buffer_size' => 50,
            ],
            'nested' => [
                'type' => 'stream',
                'path' => 'php://stderr',
                'level' => 'debug',
                'formatter' => 'monolog.formatter.json',
            ],
            'console' => [
                'type' => 'console',
                'process_psr_3_messages' => false,
                'channels' => ['!event', '!doctrine'],
            ],
            'deprecation' => [
                'type' => 'stream',
                'channels' => ['deprecation'],
                'path' => 'php://stderr',
                'formatter' => 'monolog.formatter.json',
            ],
        ],
    ],
]);
