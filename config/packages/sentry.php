<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

return App::config([
    'sentry' => [
        'dsn' => '%env(SENTRY_DSN)%',
        'register_error_listener' => false,
        'register_error_handler' => false,
        'options' => [
            'environment' => '%kernel.environment%',
            'send_default_pii' => true,
            'ignore_exceptions' => [
                AccessDeniedException::class,
                NotFoundHttpException::class,
            ],
            'max_breadcrumbs' => 50,
            'in_app_exclude' => ['%kernel.cache_dir%'],
            'in_app_include' => ['%kernel.project_dir%/src'],
            'traces_sample_rate' => 0,
            'profiles_sample_rate' => 0,
            'attach_stacktrace' => true,
        ],
        'messenger' => [
            'enabled' => true,
            'capture_soft_fails' => true,
        ],
    ],
]);
