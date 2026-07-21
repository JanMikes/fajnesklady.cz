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
            'exclude' => [
                '../src/Service/Order/OrderDisplayStatus.php',
                '../src/Service/Order/OrderDisplayStatusCase.php',
                '../src/Service/Order/OrderStatusViewModel.php',
                '../src/Service/Order/CustomerBillingSituation.php',
                '../src/Service/Order/SigningPriceViewModel.php',
                '../src/Service/Order/SigningEmailContent.php',
                '../src/Service/Order/CompletionPageViewModel.php',
                '../src/Service/Order/RentalActivatedEmailContent.php',
                '../src/Service/Order/AdminOrderStage.php',
                '../src/Service/Order/OrderPaymentOverview.php',
                '../src/Service/Order/PaymentOverviewRow.php',
                '../src/Service/Excel/ExcelColumn.php',
                '../src/Service/Excel/ExcelColumnType.php',
                '../src/Service/Excel/ExcelSheet.php',
                '../src/Service/Fakturoid/StaleFakturoidSubjectException.php',
                '../src/Service/Billing/ManualBillingReminderSchedule.php',
                '../src/Service/Payment/AllocationPlan.php',
                '../src/Service/Payment/AllocationStep.php',
                '../src/Service/Onboarding/OnboardingReminderSchedule.php',
            ],
        ],
        'App\\Validator\\' => [
            'resource' => '../src/Validator/',
        ],
        'App\\Service\\Identity\\ProvideIdentity' => [
            'alias' => 'App\\Service\\Identity\\RandomIdentityProvider',
        ],
        'App\\Service\\AresLookup' => [
            'alias' => 'App\\Service\\AresService',
        ],
        'App\\Service\\Address\\AddressValidator' => [
            'alias' => 'App\\Service\\Address\\PhotonAddressValidator',
        ],
        'App\\Service\\Fakturoid\\FakturoidApiClient' => [
            'arguments' => [
                '$vatRate' => '%env(int:FAKTUROID_VAT_RATE)%',
            ],
        ],
        'App\\Service\\Fakturoid\\FakturoidClient' => [
            'alias' => 'App\\Service\\Fakturoid\\FakturoidApiClient',
        ],
        'App\\Service\\InvoicingService' => [
            'arguments' => [
                '$invoicesDirectory' => '%kernel.project_dir%/var/invoices',
            ],
        ],
        'App\\Service\\SelfBillingService' => [
            'arguments' => [
                '$selfBillingInvoicesDirectory' => '%kernel.project_dir%/var/self_billing_invoices',
            ],
        ],
        'fakturoid.psr18_client' => [
            'class' => 'Symfony\\Component\\HttpClient\\Psr18Client',
        ],
        'Fakturoid\\FakturoidManager' => [
            'arguments' => [
                '$client' => '@fakturoid.psr18_client',
                '$clientId' => '%env(FAKTUROID_CLIENT_ID)%',
                '$clientSecret' => '%env(FAKTUROID_CLIENT_SECRET)%',
                '$accountSlug' => '%env(FAKTUROID_ACCOUNT_SLUG)%',
            ],
            'calls' => [
                ['authClientCredentials', []],
            ],
        ],
        'App\\Service\\SignatureStorage' => [
            'arguments' => [
                '$signaturesDirectory' => '%kernel.project_dir%/var/signatures',
            ],
        ],
        'App\\Command\\AdminOnboardingHandler' => [
            'arguments' => [
                '$contractsDirectory' => '%kernel.project_dir%/var/contracts',
            ],
        ],
        'App\\Service\\ContractDocumentGenerator' => [
            'arguments' => [
                '$contractsDirectory' => '%kernel.project_dir%/var/contracts',
            ],
        ],
        'App\\Service\\DocumentPdfConverter' => [
            'arguments' => [
                '$cacheDirectory' => '%kernel.cache_dir%',
            ],
        ],
        'App\\Service\\Vop\\VopDocumentGenerator' => [
            'arguments' => [
                '$vopDocumentsDirectory' => '%kernel.project_dir%/var/vop',
            ],
        ],
        'App\\Service\\Vop\\VopPdfStamper' => [
            'arguments' => [
                // Page count to leave unsigned at the end of the VOP PDF.
                // Today's template ends with two fillable form annexes
                // (withdrawal + complaint) — those stay clean.
                '$skipLastPages' => 2,
                '$signatureWidthMm' => 60,
                '$signatureMarginMm' => 12,
            ],
        ],
        'App\\Service\\ContractService' => [
            'arguments' => [
                '$contractTemplatePath' => '%kernel.project_dir%/templates/documents/contract_template.docx',
            ],
        ],
        'App\\Service\\OrderEmailAttachmentsService' => [
            'arguments' => [
                '$projectDir' => '%kernel.project_dir%',
                '$contractTemplatePath' => '%kernel.project_dir%/templates/documents/contract_template.docx',
                '$vopTemplatePath' => '%kernel.project_dir%/templates/documents/vop_template.docx',
                '$contractsDirectory' => '%kernel.project_dir%/var/contracts',
            ],
        ],
        'App\\Service\\OrderEmailAttachments' => [
            'alias' => 'App\\Service\\OrderEmailAttachmentsService',
        ],
        'App\\Event\\SendOrderPlacedEmailHandler' => null,
        'App\\Event\\SendRentalActivatedEmailHandler' => [
            'arguments' => [
                '$uploadsDirectory' => '%kernel.project_dir%/public/uploads',
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
        'Intervention\\Image\\ImageManager' => [
            'factory' => ['Intervention\\Image\\ImageManager', 'usingDriver'],
            'arguments' => ['Intervention\\Image\\Drivers\\Gd\\Driver'],
        ],
        'App\\Service\\StoragePhotoUploader' => null,
        'App\\Middleware\\DispatchDomainEventsMiddleware' => null,
        'App\\Twig\\' => [
            'resource' => '../src/Twig/',
            'exclude' => ['../src/Twig/Components/'],
        ],
        'App\\Twig\\Components\\' => [
            'resource' => '../src/Twig/Components/',
        ],
        'App\\Service\\Payment\\FioClient' => [
            'arguments' => [
                '$fioApiToken' => '%env(FIO_API_TOKEN)%',
            ],
        ],
        // GoPay payment gateway
        'GoPay\\Payments' => [
            'factory' => ['GoPay\\Api', 'payments'],
            'arguments' => [
                [
                    'goid' => '%env(GOPAY_GOID)%',
                    'clientId' => '%env(GOPAY_CLIENT_ID)%',
                    'clientSecret' => '%env(GOPAY_CLIENT_SECRET)%',
                    'gatewayUrl' => '%env(GOPAY_GATEWAY_URL)%',
                    'language' => \GoPay\Definition\Language::CZECH,
                ],
            ],
        ],
        'App\\Service\\GoPay\\GoPayClient' => [
            'alias' => 'App\\Service\\GoPay\\GoPayApiClient',
        ],
        // Sentry Monolog handlers
        'Sentry\\Monolog\\Handler' => [
            'arguments' => [
                '$hub' => '@Sentry\\State\\HubInterface',
                '$level' => \Monolog\Level::Error,
                '$bubble' => true,
                '$fillExtraContext' => true,
            ],
        ],
        'Sentry\\Monolog\\BreadcrumbHandler' => [
            'arguments' => [
                '$hub' => '@Sentry\\State\\HubInterface',
                '$level' => \Monolog\Level::Info,
            ],
        ],
        // Session storage in Postgres — uses its own lazy PDO connection from
        // DATABASE_URL (Symfony's documented pattern). Keeping it separate from
        // Doctrine's connection means the default LOCK_TRANSACTIONAL works without
        // colliding with the command bus's doctrine_transaction middleware.
        \Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler::class => [
            'arguments' => [
                '%env(resolve:DATABASE_URL)%',
                ['db_table' => 'sessions'],
            ],
        ],
    ],
]);
