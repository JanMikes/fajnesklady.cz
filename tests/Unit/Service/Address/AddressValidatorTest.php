<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Address;

use App\Service\Address\PhotonAddressValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AddressValidatorTest extends TestCase
{
    public function testReturnsVerifiedForRealCzechAddress(): void
    {
        $responseBody = json_encode([
            'features' => [
                [
                    'properties' => [
                        'countrycode' => 'CZ',
                        'street' => 'Vinohradská',
                        'housenumber' => '52',
                        'city' => 'Praha',
                        'postcode' => '12000',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $httpClient = new MockHttpClient(new MockResponse($responseBody, ['http_code' => 200]));
        $validator = new PhotonAddressValidator($httpClient, new ArrayAdapter(), $this->createStub(LoggerInterface::class));

        $result = $validator->validate('Vinohradská 52', 'Praha', '120 00');

        self::assertTrue($result->isVerified());
    }

    public function testReturnsNotFoundWhenPhotonReturnsEmpty(): void
    {
        $responseBody = json_encode(['features' => []], JSON_THROW_ON_ERROR);
        $httpClient = new MockHttpClient(new MockResponse($responseBody, ['http_code' => 200]));

        $validator = new PhotonAddressValidator($httpClient, new ArrayAdapter(), $this->createStub(LoggerInterface::class));

        $result = $validator->validate('Asdfghj 999', 'Tatratata', '99999');

        self::assertTrue($result->isNotFound());
    }

    public function testReturnsNotFoundForForeignAddress(): void
    {
        $responseBody = json_encode([
            'features' => [
                [
                    'properties' => [
                        'countrycode' => 'DE',
                        'street' => 'Vinohradská',
                        'city' => 'Berlin',
                        'postcode' => '10115',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $httpClient = new MockHttpClient(new MockResponse($responseBody, ['http_code' => 200]));
        $validator = new PhotonAddressValidator($httpClient, new ArrayAdapter(), $this->createStub(LoggerInterface::class));

        $result = $validator->validate('Vinohradská 52', 'Berlin', '10115');

        self::assertTrue($result->isNotFound());
    }

    public function testReturnsSkippedAndLogsOnTransportError(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('Address validation failed'),
                self::callback(static fn (array $context): bool => array_key_exists('exception', $context)),
            );

        $httpClient = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Network down');
        });

        $validator = new PhotonAddressValidator($httpClient, new ArrayAdapter(), $logger);

        $result = $validator->validate('Vinohradská 52', 'Praha', '120 00');

        self::assertFalse($result->isVerified());
        self::assertFalse($result->isNotFound());
    }

    public function testIdenticalNormalizedInputHitsCacheOnce(): void
    {
        $responseBody = json_encode([
            'features' => [
                [
                    'properties' => [
                        'countrycode' => 'CZ',
                        'street' => 'Vinohradská',
                        'city' => 'Praha',
                        'postcode' => '12000',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $callCount = 0;
        $httpClient = new MockHttpClient(static function () use (&$callCount, $responseBody): MockResponse {
            ++$callCount;

            return new MockResponse($responseBody, ['http_code' => 200]);
        });

        $validator = new PhotonAddressValidator($httpClient, new ArrayAdapter(), $this->createStub(LoggerInterface::class));

        $first = $validator->validate('Vinohradská 52', 'Praha', '120 00');
        $second = $validator->validate('Vinohradská 52', 'Praha', '120 00');

        self::assertTrue($first->isVerified());
        self::assertTrue($second->isVerified());
        self::assertSame(1, $callCount, 'Second identical input should hit the cache and not call HttpClient.');
    }

    public function testPostalCodeNormalizationIsCacheKeyEqivalent(): void
    {
        $responseBody = json_encode([
            'features' => [
                [
                    'properties' => [
                        'countrycode' => 'CZ',
                        'street' => 'Vinohradská',
                        'city' => 'Praha',
                        'postcode' => '12000',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $callCount = 0;
        $httpClient = new MockHttpClient(static function () use (&$callCount, $responseBody): MockResponse {
            ++$callCount;

            return new MockResponse($responseBody, ['http_code' => 200]);
        });

        $validator = new PhotonAddressValidator($httpClient, new ArrayAdapter(), $this->createStub(LoggerInterface::class));

        $first = $validator->validate('Vinohradská 52', 'Praha', '120 00');
        $second = $validator->validate('Vinohradská 52', 'Praha', '12000');

        self::assertTrue($first->isVerified());
        self::assertTrue($second->isVerified());
        self::assertSame(1, $callCount, '"110 00" and "11000" must share the same cache key.');
    }

    public function testReturnsSkippedWhenAnyFieldIsBlank(): void
    {
        $httpClient = new MockHttpClient(static function (): MockResponse {
            self::fail('Photon must not be called when input is incomplete.');
        });

        $validator = new PhotonAddressValidator($httpClient, new ArrayAdapter(), $this->createStub(LoggerInterface::class));

        $result = $validator->validate('', 'Praha', '12000');

        self::assertFalse($result->isVerified());
        self::assertFalse($result->isNotFound());
    }

    public function testSuggestResolvesStreetNameFromNameForStreetFeatureAndDropsStreetLessResults(): void
    {
        // Real shape of Photon's "Františka Formana" response: the street name lives
        // in `name` (street is null) for type=street features, plus a city-only entry
        // that must be dropped and a POI on the street that must collapse into it.
        $responseBody = json_encode([
            'features' => [
                ['properties' => [
                    'countrycode' => 'CZ', 'osm_key' => 'highway', 'type' => 'street',
                    'name' => 'Františka Formana', 'postcode' => '700 30', 'city' => 'Ostrava',
                ]],
                ['properties' => [
                    'countrycode' => 'CZ', 'osm_key' => 'highway', 'type' => 'street',
                    'name' => 'Františka Formana', 'postcode' => '724 00', 'city' => 'Ostrava',
                ]],
                // POI sitting on the street — street is filled, same address as [0].
                ['properties' => [
                    'countrycode' => 'CZ', 'osm_key' => 'amenity', 'type' => 'house',
                    'name' => 'Mateřská škola', 'street' => 'Františka Formana',
                    'postcode' => '700 30', 'city' => 'Ostrava',
                ]],
                // City-only feature — no street, must be dropped.
                ['properties' => [
                    'countrycode' => 'CZ', 'osm_key' => 'place', 'type' => 'city',
                    'name' => 'Ostrava', 'postcode' => '702 00', 'city' => 'Ostrava',
                ]],
            ],
        ], JSON_THROW_ON_ERROR);

        $httpClient = new MockHttpClient(new MockResponse($responseBody, ['http_code' => 200]));
        $validator = new PhotonAddressValidator($httpClient, new ArrayAdapter(), $this->createStub(LoggerInterface::class));

        $suggestions = $validator->suggest('Františka Formana');

        // Two street segments survive; POI deduped into the first, city-only dropped.
        self::assertCount(2, $suggestions);
        self::assertSame('Františka Formana', $suggestions[0]->street);
        self::assertSame('70030', $suggestions[0]->postalCode);
        self::assertSame('Františka Formana, 700 30 Ostrava', $suggestions[0]->displayLabel);
        self::assertSame('72400', $suggestions[1]->postalCode);
    }

    public function testSuggestPreservesPhotonRelevanceOrder(): void
    {
        // We must NOT re-rank: Photon's relevance order is authoritative. An earlier
        // house-number-first sort promoted name-matched POIs above the real streets.
        $responseBody = json_encode([
            'features' => [
                ['properties' => [
                    'countrycode' => 'CZ', 'osm_key' => 'highway', 'type' => 'street',
                    'name' => 'Hlavní', 'postcode' => '11000', 'city' => 'Praha',
                ]],
                ['properties' => [
                    'countrycode' => 'CZ', 'osm_key' => 'place', 'type' => 'house',
                    'street' => 'Hlavní', 'housenumber' => '12', 'postcode' => '11000', 'city' => 'Praha',
                ]],
            ],
        ], JSON_THROW_ON_ERROR);

        $httpClient = new MockHttpClient(new MockResponse($responseBody, ['http_code' => 200]));
        $validator = new PhotonAddressValidator($httpClient, new ArrayAdapter(), $this->createStub(LoggerInterface::class));

        $suggestions = $validator->suggest('Hlavní 12');

        self::assertCount(2, $suggestions);
        self::assertSame('', $suggestions[0]->houseNumber, 'Photon order is kept; the street stays first.');
        self::assertSame('12', $suggestions[1]->houseNumber);
    }

    public function testSuggestReturnsHouseNumberAddressWhenNumberIsTyped(): void
    {
        // Photon resolves "Františka Formana 31" (and "237/31") to a real house;
        // it must be surfaced with its number, not collapsed to a bare street.
        $responseBody = json_encode([
            'features' => [
                ['properties' => [
                    'countrycode' => 'CZ', 'osm_key' => 'place', 'type' => 'house',
                    'street' => 'Františka Formana', 'housenumber' => '31',
                    'postcode' => '70030', 'city' => 'Ostrava',
                ]],
            ],
        ], JSON_THROW_ON_ERROR);

        $httpClient = new MockHttpClient(new MockResponse($responseBody, ['http_code' => 200]));
        $validator = new PhotonAddressValidator($httpClient, new ArrayAdapter(), $this->createStub(LoggerInterface::class));

        $suggestions = $validator->suggest('Františka Formana 237/31');

        self::assertCount(1, $suggestions);
        self::assertSame('31', $suggestions[0]->houseNumber);
        self::assertSame('Františka Formana 31, 700 30 Ostrava', $suggestions[0]->displayLabel);
    }

    public function testSuggestDropsResultsWhoseStreetDoesNotMatchTheQuery(): void
    {
        // A villa NAMED "Františka" on Masarykova (matched by name, not street) must
        // be dropped; the real "Františka Diviše" street kept.
        $responseBody = json_encode([
            'features' => [
                ['properties' => [
                    'countrycode' => 'CZ', 'osm_key' => 'building', 'type' => 'house',
                    'name' => 'Františka', 'street' => 'Masarykova', 'housenumber' => '184',
                    'postcode' => '76326', 'city' => 'Luhačovice',
                ]],
                ['properties' => [
                    'countrycode' => 'CZ', 'osm_key' => 'highway', 'type' => 'street',
                    'name' => 'Františka Diviše', 'postcode' => '10400', 'city' => 'Praha',
                ]],
            ],
        ], JSON_THROW_ON_ERROR);

        $httpClient = new MockHttpClient(new MockResponse($responseBody, ['http_code' => 200]));
        $validator = new PhotonAddressValidator($httpClient, new ArrayAdapter(), $this->createStub(LoggerInterface::class));

        $suggestions = $validator->suggest('Františka');

        self::assertCount(1, $suggestions);
        self::assertSame('Františka Diviše', $suggestions[0]->street);
    }

    public function testSuggestSkipsForeignFeatures(): void
    {
        $responseBody = json_encode([
            'features' => [
                ['properties' => [
                    'countrycode' => 'DE', 'osm_key' => 'highway', 'type' => 'street',
                    'name' => 'Hauptstraße', 'postcode' => '10115', 'city' => 'Berlin',
                ]],
                ['properties' => [
                    'countrycode' => 'CZ', 'osm_key' => 'highway', 'type' => 'street',
                    'name' => 'Hlavní', 'postcode' => '11000', 'city' => 'Praha',
                ]],
            ],
        ], JSON_THROW_ON_ERROR);

        $httpClient = new MockHttpClient(new MockResponse($responseBody, ['http_code' => 200]));
        $validator = new PhotonAddressValidator($httpClient, new ArrayAdapter(), $this->createStub(LoggerInterface::class));

        $suggestions = $validator->suggest('Hlavní');

        self::assertCount(1, $suggestions);
        self::assertSame('Praha', $suggestions[0]->city);
    }
}
