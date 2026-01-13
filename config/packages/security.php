<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use App\Entity\User;

return App::config([
    'security' => [
        'password_hashers' => [
            'Symfony\\Component\\Security\\Core\\User\\PasswordAuthenticatedUserInterface' => 'auto',
        ],
        'providers' => [
            'app_user_provider' => [
                'entity' => [
                    'class' => User::class,
                    'property' => 'email',
                ],
            ],
        ],
        'firewalls' => [
            'dev' => [
                'pattern' => '^/(_(profiler|wdt)|css|images|js)/',
                'security' => false,
            ],
            'main' => [
                'lazy' => true,
                'provider' => 'app_user_provider',
                'form_login' => [
                    'login_path' => 'app_login',
                    'check_path' => 'app_login',
                    'enable_csrf' => false,
                    'default_target_path' => 'portal_dashboard',
                ],
                'logout' => [
                    'path' => 'app_logout',
                    'target' => 'app_login',
                ],
                'remember_me' => [
                    'secret' => '%kernel.secret%',
                    'lifetime' => 604800, // 7 days
                    'path' => '/',
                    'always_remember_me' => false,
                ],
                'switch_user' => [
                    'role' => 'ROLE_ADMIN',
                    'parameter' => '_switch_user',
                ],
            ],
        ],
        'access_control' => [
            ['path' => '^/-/health-check/liveness', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/portal', 'roles' => 'ROLE_USER'],
            ['path' => '^/login', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/register', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/reset-password', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/verify-email', 'roles' => 'PUBLIC_ACCESS'],
        ],
        'role_hierarchy' => [
            'ROLE_LANDLORD' => 'ROLE_USER',
            'ROLE_ADMIN' => ['ROLE_LANDLORD', 'ROLE_USER'],
        ],
    ],
]);
