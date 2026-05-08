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
            // ARES lookup rate limiter - 60 attempts per hour per IP
            'ares_lookup' => [
                'policy' => 'sliding_window',
                'limit' => 60,
                'interval' => '1 hour',
            ],
            // GoPay webhook rate limiter - per-IP, token bucket so legitimate
            // GoPay retries get burst capacity while abuse gets throttled.
            // 60 burst + 60 refill/min is generous for real traffic but
            // tight enough to stop scripted abuse.
            'gopay_webhook' => [
                'policy' => 'token_bucket',
                'limit' => 60,
                'rate' => [
                    'interval' => '1 minute',
                    'amount' => 60,
                ],
            ],
        ],
    ],
]);
