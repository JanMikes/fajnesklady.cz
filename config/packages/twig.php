<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use App\Service\PriceCalculator;

return App::config([
    'twig' => [
        'file_name_pattern' => '*.twig',
        'form_themes' => [
            'form/tailwind_theme.html.twig',
        ],
        'date' => [
            'timezone' => 'Europe/Prague',
        ],
        'globals' => [
            // GoPay recurring-payment max-amount disclosure multiplier.
            // See PriceCalculator::RECURRING_PAYMENT_MAX_MULTIPLIER for the rationale.
            'recurring_payment_max_multiplier' => PriceCalculator::RECURRING_PAYMENT_MAX_MULTIPLIER,
        ],
    ],
]);
