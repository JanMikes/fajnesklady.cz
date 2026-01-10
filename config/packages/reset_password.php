<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'symfonycasts_reset_password' => [
        'request_password_repository' => 'symfonycasts.reset_password.fake_request_repository',
    ],
]);
