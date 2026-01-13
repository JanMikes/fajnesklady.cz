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
        'App\\Command\\' => [
            'resource' => '../src/Command/*Handler.php',
        ],
        'App\\Console\\' => [
            'resource' => '../src/Console/',
        ],
        'App\\Controller\\' => [
            'resource' => '../src/Controller/',
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
        ],
        'App\\Service\\Identity\\ProvideIdentity' => [
            'alias' => 'App\\Service\\Identity\\RandomIdentityProvider',
        ],
        'App\\Service\\AresLookup' => [
            'alias' => 'App\\Service\\AresService',
        ],
        'App\\Service\\ContractDocumentGenerator' => [
            'arguments' => [
                '$contractsDirectory' => '%kernel.project_dir%/var/contracts',
            ],
        ],
        'App\\Service\\ContractService' => [
            'arguments' => [
                '$contractTemplatePath' => '%kernel.project_dir%/templates/documents/contract_template.docx',
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
        'App\\Twig\\' => [
            'resource' => '../src/Twig/',
            'exclude' => ['../src/Twig/Components/'],
        ],
        'App\\Twig\\Components\\' => [
            'resource' => '../src/Twig/Components/',
        ],
    ],
]);
