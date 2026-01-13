<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\HttpFoundation\Session\SessionFactory;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

return App::config([
    'services' => [
        'App\\DataFixtures\\' => [
            'resource' => '../../../fixtures/',
        ],
        'security.untracked_token_storage' => [
            'class' => TokenStorage::class,
            'public' => true,
        ],
        'session.factory' => [
            'class' => SessionFactory::class,
            'arguments' => [
                '@request_stack',
                '@session.storage.factory.mock_file',
            ],
            'public' => true,
        ],
    ],
]);
