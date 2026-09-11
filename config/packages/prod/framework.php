<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        // Browser-facing security headers used to be declared here under
        // `http_client.default_options.headers`, which put them on OUTGOING
        // API requests instead of on responses — visitors never received them.
        // They now live in App\Event\SecurityHeadersSubscriber.
    ],
]);
