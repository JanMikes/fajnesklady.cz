<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        'http_client' => [
            'default_options' => [
                'headers' => [
                    'X-Frame-Options' => 'DENY',
                    'X-Content-Type-Options' => 'nosniff',
                    'X-XSS-Protection' => '1; mode=block',
                    'Referrer-Policy' => 'strict-origin-when-cross-origin',
                    'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
                ],
            ],
        ],
    ],
]);
