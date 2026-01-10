<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'web_profiler' => [
        'toolbar' => true,
    ],
    'framework' => [
        'profiler' => [
            'collect_serializer_data' => true,
        ],
    ],
]);
