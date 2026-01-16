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
        // Make messenger buses public for tests
        'test.command.bus' => [
            'alias' => 'command.bus',
            'public' => true,
        ],
        'test.query.bus' => [
            'alias' => 'query.bus',
            'public' => true,
        ],
        'test.event.bus' => [
            'alias' => 'event.bus',
            'public' => true,
        ],
    ],
]);
