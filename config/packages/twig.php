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

            // Google Tag Manager container. Blank it per-environment (staging,
            // local) to keep that traffic out of the operator's container —
            // an empty value drops both GTM snippets AND the cookie bar, which
            // is correct: without GTM the site sets only strictly necessary
            // cookies, and those need no consent.
            'gtm_container_id' => '%env(string:GTM_CONTAINER_ID)%',
        ],
    ],
]);
