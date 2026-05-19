<?php

declare(strict_types=1);

namespace App\Service\Place;

use App\Entity\Place;

final readonly class PlaceAddressFormatter
{
    private const string GOOGLE_NAVIGATION_URL = 'https://www.google.com/maps/dir/?api=1&destination=%s';

    /**
     * Single human-readable address line for a place. Never produces a leading
     * comma: when {@see Place::$address} is null/empty we fall back to
     * "{postalCode} {city}".
     */
    public function format(Place $place): string
    {
        if (null !== $place->address && '' !== $place->address) {
            return sprintf('%s, %s %s', $place->address, $place->postalCode, $place->city);
        }

        return trim(sprintf('%s %s', $place->postalCode, $place->city));
    }

    /**
     * Google Maps navigation-intent URL (opens turn-by-turn). Prefers GPS
     * coordinates when present (always unambiguous), otherwise builds a
     * destination from the street address. Returns null when there is nothing
     * useful to route to so callers can hide the CTA.
     */
    public function navigationUrl(Place $place): ?string
    {
        if (null !== $place->latitude && null !== $place->longitude) {
            return sprintf(self::GOOGLE_NAVIGATION_URL, $place->latitude.','.$place->longitude);
        }

        if (null !== $place->address && '' !== $place->address) {
            $destination = sprintf('%s, %s %s', $place->address, $place->postalCode, $place->city);

            return sprintf(self::GOOGLE_NAVIGATION_URL, rawurlencode($destination));
        }

        return null;
    }

    public function hasNavigation(Place $place): bool
    {
        return null !== $this->navigationUrl($place);
    }
}
