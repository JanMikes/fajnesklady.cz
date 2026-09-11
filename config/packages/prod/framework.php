<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        // This file used to declare HSTS, X-Frame-Options, X-Content-Type-Options
        // and Referrer-Policy under `http_client.default_options.headers`, which
        // is the config for headers on OUTGOING HttpClient requests (GoPay, ARES,
        // Fakturoid) — response headers have no meaning there, so the block did
        // nothing but add noise to our API calls.
        //
        // It was NOT a gap: the browser-facing headers are set at the edge by
        // Traefik's `sec-headers` middleware, part of the `public-edge@file`
        // chain on the fajnesklady router (lily.srv:
        // infra/traefik/dynamic/middlewares.yml). The edge values are stricter
        // than what stood here — HSTS 2y with `preload` vs 1y — so do not
        // re-add them at the app layer thinking they are missing. Verify with
        // `curl -sI https://fajnesklady.cz/` before changing anything here.
    ],
]);
