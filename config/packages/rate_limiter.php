<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        'rate_limiter' => [
            // Login rate limiter - 5 attempts per 15 minutes per IP
            'login' => [
                'policy' => 'sliding_window',
                'limit' => 5,
                'interval' => '15 minutes',
            ],
            // Registration rate limiter - 3 attempts per hour per IP
            'registration' => [
                'policy' => 'sliding_window',
                'limit' => 3,
                'interval' => '1 hour',
            ],
            // Password reset rate limiter - 3 attempts per hour per IP
            'password_reset' => [
                'policy' => 'sliding_window',
                'limit' => 3,
                'interval' => '1 hour',
            ],
            // Email verification rate limiter - 5 attempts per hour per IP
            'email_verification' => [
                'policy' => 'sliding_window',
                'limit' => 5,
                'interval' => '1 hour',
            ],
        ],
    ],
]);
