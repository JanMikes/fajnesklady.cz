<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'services' => [
        '_defaults' => [
            'autowire' => true,
            'autoconfigure' => true,
        ],
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
        'App\\Event\\DomainEventsSubscriber' => null,
        'App\\Event\\SendPasswordResetEmailHandler' => null,
        'App\\Event\\SendVerificationEmailHandler' => null,
        'App\\Event\\SendWelcomeEmailHandler' => null,
        'App\\Event\\SendOrderConfirmationEmailHandler' => null,
        'App\\Event\\SendContractReadyEmailHandler' => null,
        'App\\Event\\SendContractExpiringReminderHandler' => null,
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
        ],
        'App\\Service\\Identity\\ProvideIdentity' => [
            'alias' => 'App\\Service\\Identity\\RandomIdentityProvider',
        ],
        'App\\Service\\ContractDocumentGenerator' => [
            'arguments' => [
                '$contractsDirectory' => '%kernel.project_dir%/var/contracts',
            ],
        ],
    ],
]);
