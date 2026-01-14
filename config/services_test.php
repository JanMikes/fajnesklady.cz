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
        'App\\Tests\\Mock\\MockFakturoidClient' => [
            'tags' => [['name' => 'kernel.reset', 'method' => 'reset']],
        ],
        'App\\Service\\Fakturoid\\FakturoidClient' => [
            'alias' => 'App\\Tests\\Mock\\MockFakturoidClient',
        ],
        'Symfony\\Component\\PasswordHasher\\Hasher\\UserPasswordHasherInterface' => [
            'alias' => 'security.user_password_hasher',
            'public' => true,
        ],
        // Mock clock for deterministic time in tests
        'Psr\\Clock\\ClockInterface' => [
            'class' => 'Symfony\\Component\\Clock\\MockClock',
            'arguments' => ['2025-06-15 12:00:00 UTC'],
        ],
    ],
]);
