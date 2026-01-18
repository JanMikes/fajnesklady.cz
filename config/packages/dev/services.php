<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'services' => [
        'App\\DataFixtures\\' => [
            'resource' => '../../../fixtures/',
        ],
        'App\\DataFixtures\\ContractFixtures' => [
            'arguments' => [
                '$contractTemplatePath' => '%kernel.project_dir%/templates/documents/contract_template.docx',
            ],
        ],
        'App\\DataFixtures\\InvoiceFixtures' => [
            'arguments' => [
                '$invoicesDirectory' => '%kernel.project_dir%/var/invoices',
            ],
        ],
    ],
]);
