<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'services' => [
        'security.untracked_token_storage' => [
            'class' => 'Symfony\\Component\\Security\\Core\\Authentication\\Token\\Storage\\TokenStorage',
            'public' => true,
        ],
        'session.factory' => [
            'class' => 'Symfony\\Component\\HttpFoundation\\Session\\SessionFactory',
            'arguments' => [
                '@request_stack',
                '@session.storage.factory.mock_file',
            ],
            'public' => true,
        ],
    ],
]);
