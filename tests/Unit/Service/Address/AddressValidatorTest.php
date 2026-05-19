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
        $validator = new PhotonAddressValidator($httpClient, new ArrayAdapter(), $this->createMock(LoggerInterface::class));

        $result = $validator->validate('Vinohradská 52', 'Praha', '120 00');

        self::assertTrue($result->isVerified());
    }

    public function testReturnsNotFoundWhenPhotonReturnsEmpty(): void
    {
        $responseBody = json_encode(['features' => []], JSON_THROW_ON_ERROR);
        $httpClient = new MockHttpClient(new MockResponse($responseBody, ['http_code' => 200]));

        $validator = new PhotonAddressValidator($httpClient, new ArrayAdapter(), $this->createMock(LoggerInterface::class));

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
        $validator = new PhotonAddressValidator($httpClient, new ArrayAdapter(), $this->createMock(LoggerInterface::class));

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

        $validator = new PhotonAddressValidator($httpClient, new ArrayAdapter(), $this->createMock(LoggerInterface::class));

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

        $validator = new PhotonAddressValidator($httpClient, new ArrayAdapter(), $this->createMock(LoggerInterface::class));

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

        $validator = new PhotonAddressValidator($httpClient, new ArrayAdapter(), $this->createMock(LoggerInterface::class));

        $result = $validator->validate('', 'Praha', '12000');

        self::assertFalse($result->isVerified());
        self::assertFalse($result->isNotFound());
    }
}
