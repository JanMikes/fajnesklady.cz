<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        'secret' => '%env(APP_SECRET)%',
        'session' => [
            'handler_id' => \Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler::class,
            'cookie_lifetime' => 2592000,   // 30 days
            'gc_maxlifetime' => 2592000,    // 30 days
            'cookie_secure' => 'auto',
            'cookie_samesite' => 'lax',
        ],
        'trusted_proxies' => '%env(TRUSTED_PROXIES)%',
        'trusted_headers' => ['x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-forwarded-port', 'x-forwarded-prefix'],
    ],
]);
