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
        ],
        // Re-register all namespaces to inherit public: true from _defaults
        'App\\Command\\' => [
            'resource' => '../src/Command/*Handler.php',
        ],
        'App\\Console\\' => [
            'resource' => '../src/Console/',
        ],
        'App\\Controller\\' => [
            'resource' => '../src/Controller/',
        ],
        'App\\DataFixtures\\' => [
            'resource' => '../src/DataFixtures/',
        ],
        'App\\Event\\' => [
            'resource' => '../src/Event/*{Handler,Subscriber}.php',
        ],
        'App\\Form\\' => [
            'resource' => '../src/Form/*FormType.php',
        ],
        'App\\Query\\' => [
            'resource' => '../src/Query/*Query.php',
        ],
        'App\\Query\\QueryBus' => null,
        'App\\Repository\\' => [
            'resource' => '../src/Repository/',
        ],
        'App\\Service\\' => [
            'resource' => '../src/Service/',
            'exclude' => '../src/Service/AresResult.php',
        ],
        // Services with constructor arguments (must re-specify after resource override)
        'App\\Service\\ContractDocumentGenerator' => [
            'arguments' => [
                '$contractsDirectory' => '%kernel.project_dir%/var/contracts',
            ],
        ],
        'App\\Service\\PlaceFileUploader' => [
            'arguments' => [
                '$uploadsDirectory' => '%kernel.project_dir%/public/uploads',
            ],
        ],
        'App\\Service\\StorageTypePhotoUploader' => [
            'arguments' => [
                '$uploadsDirectory' => '%kernel.project_dir%/public/uploads',
            ],
        ],
        // Test-specific overrides
        'App\\Tests\\Support\\PredictableIdentityProvider' => [
            'tags' => [['name' => 'kernel.reset', 'method' => 'reset']],
        ],
        'App\\Service\\Identity\\ProvideIdentity' => [
            'alias' => 'App\\Tests\\Support\\PredictableIdentityProvider',
        ],
        'App\\Tests\\Mock\\MockAresLookup' => [
            'tags' => [['name' => 'kernel.reset', 'method' => 'reset']],
        ],
        'App\\Service\\AresLookup' => [
            'alias' => 'App\\Tests\\Mock\\MockAresLookup',
        ],
        'Symfony\\Component\\PasswordHasher\\Hasher\\UserPasswordHasherInterface' => [
            'alias' => 'security.user_password_hasher',
            'public' => true,
        ],
    ],
]);
