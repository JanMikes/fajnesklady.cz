<?php

declare(strict_types=1);

namespace App\Service\Address;

use App\Value\Address\AddressSuggestion;
use App\Value\Address\AddressValidationResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class PhotonAddressValidator implements AddressValidator
{
    private const string PHOTON_URL = 'https://photon.komoot.io/api/';
    private const string USER_AGENT = 'fajnesklady.cz address validation (info@fajnesklady.cz)';
    private const float REQUEST_TIMEOUT_SECONDS = 3.0;
    private const int MAX_SUGGESTIONS = 4;
    private const int VALIDATE_CACHE_TTL_SECONDS = 7 * 24 * 60 * 60;
    // Suggestions are keyed by the typed query; addresses are stable, so cache the
    // Photon response for a month to keep external calls (and latency) down. Validate
    // stays at 7 days so registry corrections to a confirmed address get re-checked.
    private const int SUGGEST_CACHE_TTL_SECONDS = 30 * 24 * 60 * 60;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    public function validate(?string $street, ?string $city, ?string $postalCode): AddressValidationResult
    {
        if (null === $street || '' === trim($street)
            || null === $city || '' === trim($city)
            || null === $postalCode || '' === trim($postalCode)) {
            return AddressValidationResult::skipped();
        }

        $normalizedStreet = mb_strtolower(trim($street));
        $normalizedCity = mb_strtolower(trim($city));
        $normalizedPostalCode = preg_replace('/\s+/', '', trim($postalCode)) ?? '';

        $cacheKey = 'address_validation.'.md5($normalizedStreet.'|'.$normalizedCity.'|'.$normalizedPostalCode);

        try {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($street, $city, $postalCode, $normalizedStreet, $normalizedPostalCode): AddressValidationResult {
                $item->expiresAfter(self::VALIDATE_CACHE_TTL_SECONDS);

                $features = $this->fetchFeatures(sprintf('%s, %s %s, Česko', trim($street), trim($postalCode), trim($city)), 5);

                foreach ($features as $feature) {
                    if ($this->featureMatches($feature, $normalizedStreet, $normalizedPostalCode)) {
                        return AddressValidationResult::verified();
                    }
                }

                return AddressValidationResult::notFound();
            });
        } catch (\Throwable $e) {
            $this->logger->warning('Address validation failed; skipping check', [
                'street' => $street,
                'city' => $city,
                'postal_code' => $postalCode,
                'exception' => $e,
            ]);

            return AddressValidationResult::skipped();
        }
    }

    /**
     * @return list<AddressSuggestion>
     */
    public function suggest(string $query): array
    {
        $normalizedQuery = mb_strtolower(trim($query));
        if ('' === $normalizedQuery) {
            return [];
        }

        // v4: street + house layers (so a typed house number resolves to a real
        // address) plus a query-relevance filter to strip the name-matched POIs the
        // house layer would otherwise pull in. Bust older cached formats.
        $cacheKey = 'address_suggest.v4.'.md5($normalizedQuery);

        try {
            $suggestions = $this->cache->get($cacheKey, function (ItemInterface $item) use ($query): array {
                $item->expiresAfter(self::SUGGEST_CACHE_TTL_SECONDS);

                // Street + house layers so typing a number ("… 31", "… 237/31")
                // resolves to the actual house. Over-fetch (15) so foreign results and
                // name-matched POIs dropped below still leave a full Czech list; the
                // relevance filter (matchesQueryStreet) then removes addresses whose
                // street doesn't match what was typed. Photon's relevance order is kept.
                $features = $this->fetchFeatures(trim($query), 15, ['street', 'house']);

                $suggestions = [];
                $seen = [];
                foreach ($features as $feature) {
                    $properties = $feature['properties'] ?? null;
                    if (!is_array($properties)) {
                        continue;
                    }

                    if ('CZ' !== ($properties['countrycode'] ?? null)) {
                        continue;
                    }

                    $street = $this->resolveStreet($properties);
                    $houseNumber = (string) ($properties['housenumber'] ?? '');
                    $city = (string) ($properties['city'] ?? $properties['town'] ?? $properties['village'] ?? '');
                    $postalCode = preg_replace('/\s+/', '', (string) ($properties['postcode'] ?? '')) ?? '';

                    // A residential address needs a street. Dropping street-less
                    // features removes the confusing "700 30 Ostrava" (city + PSČ,
                    // no street) entries that showed up for a street query.
                    if ('' === $street || '' === $city || '' === $postalCode) {
                        continue;
                    }

                    // Drop results matched by their POI name rather than by the street
                    // the customer typed (a villa named "Františka" on Masarykova, a
                    // "Františkánský klášter", a foreign street) — and unrelated streets
                    // like "Františka Metelce" for a "Františka Formana" query.
                    if (!$this->matchesQueryStreet($query, $street, $city)) {
                        continue;
                    }

                    // Collapse duplicates — e.g. a POI sitting on the street and the
                    // street feature itself both resolve to the same address.
                    $dedupeKey = mb_strtolower($street.'|'.$houseNumber.'|'.$city.'|'.$postalCode);
                    if (isset($seen[$dedupeKey])) {
                        continue;
                    }
                    $seen[$dedupeKey] = true;

                    $suggestions[] = new AddressSuggestion(
                        street: $street,
                        houseNumber: $houseNumber,
                        city: $city,
                        postalCode: $postalCode,
                        displayLabel: $this->buildDisplayLabel($street, $houseNumber, $postalCode, $city),
                    );
                }

                // Keep Photon's relevance order — do NOT re-sort. (An earlier
                // house-number-first sort promoted name-matched POIs above the real
                // streets; relevance is Photon's job, not ours.)
                return $suggestions;
            });

            // Cap how many reach the dropdown. Kept outside the cached closure so the
            // count can be tuned without busting cached entries.
            return array_slice($suggestions, 0, self::MAX_SUGGESTIONS);
        } catch (\Throwable $e) {
            $this->logger->warning('Address suggest failed', [
                'query' => $query,
                'exception' => $e,
            ]);

            return [];
        }
    }

    /**
     * Photon stores the street name of a `type=street` (highway) feature in `name`,
     * not in `street` (which it only fills for house / POI features). Without this
     * fallback those street results lose their name and render as "<postcode> <city>".
     * Gated to street-type features so a city / locality `name` is never taken as a
     * street. Mirrors the `street ?? name` fallback already used by featureMatches().
     *
     * @param array<string, mixed> $properties
     */
    private function resolveStreet(array $properties): string
    {
        $street = trim((string) ($properties['street'] ?? ''));
        if ('' !== $street) {
            return $street;
        }

        $isStreetFeature = 'street' === ($properties['type'] ?? null)
            || 'highway' === ($properties['osm_key'] ?? null);

        return $isStreetFeature ? trim((string) ($properties['name'] ?? '')) : '';
    }

    /**
     * Photon also matches features by their *name*, so a "Františka" query surfaces a
     * villa named "Františka" (street Masarykova) or a "Františkánský klášter" whose
     * street is unrelated. Keep a suggestion only when every non-numeric query token
     * appears in its street + city, so real streets/houses on the typed street stay
     * and name-matched noise is dropped. Numeric tokens (house numbers like 31 or
     * 237/31) are matched by Photon, not here, so they are skipped.
     */
    private function matchesQueryStreet(string $query, string $street, string $city): bool
    {
        $haystack = $this->slugifyForCompare($street.' '.$city);

        foreach (preg_split('/\s+/', trim($query)) ?: [] as $token) {
            // Skip house-number tokens (31, 237/31, 12a, …); Photon matches those.
            if ('' === $token || 1 === preg_match('/\d/', $token)) {
                continue;
            }

            $needle = $this->slugifyForCompare($token);
            if ('' !== $needle && !str_contains($haystack, $needle)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $layers Photon `layer` filters (e.g. street, house)
     *
     * @return list<array<string, mixed>>
     */
    private function fetchFeatures(string $query, int $limit, array $layers = []): array
    {
        $url = self::PHOTON_URL.'?'.http_build_query([
            'q' => $query,
            'limit' => $limit,
            'lang' => 'default',
        ], '', '&', \PHP_QUERY_RFC3986);

        // Photon (Spring) binds `layer` as a repeated param (?layer=a&layer=b).
        // Symfony's `query` option would encode an array as layer[0]=…, which Photon
        // ignores, so append the repeated params to the URL by hand.
        foreach ($layers as $layer) {
            $url .= '&layer='.rawurlencode($layer);
        }

        $response = $this->httpClient->request('GET', $url, [
            'timeout' => self::REQUEST_TIMEOUT_SECONDS,
            'headers' => [
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'application/json',
            ],
        ]);

        $data = $response->toArray();
        $features = $data['features'] ?? [];

        if (!is_array($features)) {
            return [];
        }

        return array_values(array_filter($features, 'is_array'));
    }

    /**
     * @param array<string, mixed> $feature
     */
    private function featureMatches(array $feature, string $normalizedStreet, string $normalizedPostalCode): bool
    {
        $properties = $feature['properties'] ?? null;
        if (!is_array($properties)) {
            return false;
        }

        if ('CZ' !== ($properties['countrycode'] ?? null)) {
            return false;
        }

        $featurePostalCode = preg_replace('/\s+/', '', (string) ($properties['postcode'] ?? '')) ?? '';
        if ('' === $featurePostalCode || $featurePostalCode !== $normalizedPostalCode) {
            return false;
        }

        $featureStreet = $this->slugifyForCompare((string) ($properties['street'] ?? $properties['name'] ?? ''));
        if ('' === $featureStreet) {
            return false;
        }

        $inputStreetToken = $this->slugifyForCompare($this->firstStreetToken($normalizedStreet));
        if ('' === $inputStreetToken) {
            return false;
        }

        return str_contains($featureStreet, $inputStreetToken);
    }

    private function firstStreetToken(string $street): string
    {
        $tokens = preg_split('/\s+/', trim($street)) ?: [];
        foreach ($tokens as $token) {
            if (!preg_match('/^\d/', $token)) {
                return $token;
            }
        }

        return $tokens[0] ?? '';
    }

    private function slugifyForCompare(string $value): string
    {
        $slugger = new AsciiSlugger();

        return mb_strtolower($slugger->slug($value)->toString());
    }

    private function buildDisplayLabel(string $street, string $houseNumber, string $postalCode, string $city): string
    {
        $left = trim($street.' '.$houseNumber);
        $postalFormatted = '' !== $postalCode && 5 === strlen($postalCode)
            ? substr($postalCode, 0, 3).' '.substr($postalCode, 3)
            : $postalCode;

        if ('' === $left) {
            return trim($postalFormatted.' '.$city);
        }

        return $left.', '.trim($postalFormatted.' '.$city);
    }
}
