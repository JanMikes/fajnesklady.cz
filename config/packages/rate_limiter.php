<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        'rate_limiter' => [
            // Registration rate limiter - 3 attempts per hour per IP
            'registration' => [
                'policy' => 'sliding_window',
                'limit' => 10,
                'interval' => '1 hour',
            ],
            // Password reset rate limiter - 3 attempts per hour per IP
            'password_reset' => [
                'policy' => 'sliding_window',
                'limit' => 10,
                'interval' => '1 hour',
            ],
            // Email verification rate limiter - 5 attempts per hour per IP
            'email_verification' => [
                'policy' => 'sliding_window',
                'limit' => 10,
                'interval' => '1 hour',
            ],
        ],
    ],
]);
