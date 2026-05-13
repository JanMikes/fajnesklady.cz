<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'symfonycasts_tailwind' => [
        // Use the binary baked into the base image when it advertises one;
        // fall back to the bundle's download flow otherwise (CI on a stale
        // base image, dev outside the container, etc.).
        'binary' => $_SERVER['TAILWIND_BINARY'] ?? null,
        'binary_version' => 'v4.1.11',
    ],
]);
