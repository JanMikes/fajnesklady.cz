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
            // Legal ceiling for any single recurring charge (CZK) — disclosed
            // verbatim in Podmínky opakovaných plateb čl. III.
            // See PriceCalculator::MAX_RECURRING_PAYMENT_AMOUNT_IN_HALER.
            'recurring_payment_legal_max_in_czk' => PriceCalculator::MAX_RECURRING_PAYMENT_AMOUNT_IN_HALER / 100,
        ],
    ],
]);
