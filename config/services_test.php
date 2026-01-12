<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'services' => [
        '_defaults' => [
            'autowire' => true,
            'autoconfigure' => true,
            'public' => true,
        ],
        'test.service_container' => [
            'alias' => 'service_container',
            'public' => true,
        ],
        'App\\Command\\' => [
            'resource' => '../src/Command/*Handler.php',
            'public' => true,
        ],
        'App\\Controller\\' => [
            'resource' => '../src/Controller/',
            'public' => true,
        ],
        'App\\DataFixtures\\' => [
            'resource' => '../src/DataFixtures/',
            'public' => true,
        ],
        'App\\Event\\DomainEventsSubscriber' => [
            'public' => true,
        ],
        'App\\Event\\SendPasswordResetEmailHandler' => [
            'public' => true,
        ],
        'App\\Event\\SendVerificationEmailHandler' => [
            'public' => true,
        ],
        'App\\Event\\SendWelcomeEmailHandler' => [
            'public' => true,
        ],
        'App\\Form\\' => [
            'resource' => '../src/Form/*FormType.php',
            'public' => true,
        ],
        'App\\Query\\' => [
            'resource' => '../src/Query/*Query.php',
            'public' => true,
        ],
        'App\\Query\\QueryBus' => [
            'public' => true,
        ],
        'App\\Repository\\' => [
            'resource' => '../src/Repository/',
            'public' => true,
        ],
        'App\\Service\\' => [
            'resource' => '../src/Service/',
            'public' => true,
        ],
        'App\\Tests\\Support\\PredictableIdentityProvider' => [
            'tags' => [['name' => 'kernel.reset', 'method' => 'reset']],
        ],
        'App\\Service\\Identity\\ProvideIdentity' => [
            'alias' => 'App\\Tests\\Support\\PredictableIdentityProvider',
        ],
    ],
]);
