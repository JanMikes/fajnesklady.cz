<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'symfonycasts_verify_email' => [
        'lifetime' => 604800, // 7 days
    ],
]);
