<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AresService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class AresServiceTest extends TestCase
{
    public function testLoadByCompanyIdReturnsResultForExistingCompany(): void
    {
        $responseBody = json_encode([
            'ico' => '11678631',
            'obchodniJmeno' => 'Mekmann s.r.o.',
            'dic' => 'CZ11678631',
            'sidlo' => [
                'nazevUlice' => 'Dvořákova',
                'cisloDomovni' => 780,
                'nazevObce' => 'Frýdlant nad Ostravicí',
                'psc' => 73911,
                'textovaAdresa' => 'Dvořákova 780, Frýdlant, 73911 Frýdlant nad Ostravicí',
            ],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody, ['http_code' => 200]);
        $httpClient = new MockHttpClient($mockResponse);

        $service = new AresService($httpClient);
        $result = $service->loadByCompanyId('11678631');

        self::assertNotNull($result);
        self::assertSame('11678631', $result->companyId);
        self::assertSame('Mekmann s.r.o.', $result->companyName);
        self::assertSame('CZ11678631', $result->companyVatId);
        self::assertSame('Dvořákova 780', $result->street);
        self::assertSame('Frýdlant nad Ostravicí', $result->city);
        self::assertSame('73911', $result->postalCode);
    }

    public function testLoadByCompanyIdReturnsResultWithOrientationNumber(): void
    {
        $responseBody = json_encode([
            'ico' => '27082440',
            'obchodniJmeno' => 'Alza.cz a.s.',
            'dic' => 'CZ27082440',
            'sidlo' => [
                'nazevUlice' => 'Jankovcova',
                'cisloDomovni' => 1522,
                'cisloOrientacni' => 53,
                'nazevObce' => 'Praha',
                'nazevMestskeCastiObvodu' => 'Praha 7',
                'psc' => 17000,
            ],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody, ['http_code' => 200]);
        $httpClient = new MockHttpClient($mockResponse);

        $service = new AresService($httpClient);
        $result = $service->loadByCompanyId('27082440');

        self::assertNotNull($result);
        self::assertSame('27082440', $result->companyId);
        self::assertSame('Alza.cz a.s.', $result->companyName);
        self::assertSame('Jankovcova 1522/53', $result->street);
        self::assertSame('Praha', $result->city);
        self::assertSame('17000', $result->postalCode);
    }

    public function testLoadByCompanyIdReturnsNullForNonExistentCompany(): void
    {
        $responseBody = json_encode([
            'kod' => 'NENALEZENO',
            'popis' => 'Nebyl nalezen žádný subjekt.',
            'subKod' => 'VYSTUP_SUBJEKT_NENALEZEN',
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody, ['http_code' => 404]);
        $httpClient = new MockHttpClient($mockResponse);

        $service = new AresService($httpClient);
        $result = $service->loadByCompanyId('12345678');

        self::assertNull($result);
    }

    public function testLoadByCompanyIdReturnsNullOnHttpError(): void
    {
        $mockResponse = new MockResponse('Internal Server Error', ['http_code' => 500]);
        $httpClient = new MockHttpClient($mockResponse);

        $service = new AresService($httpClient);
        $result = $service->loadByCompanyId('11678631');

        self::assertNull($result);
    }

    public function testLoadByCompanyIdReturnsNullOnInvalidJson(): void
    {
        $mockResponse = new MockResponse('invalid json', ['http_code' => 200]);
        $httpClient = new MockHttpClient($mockResponse);

        $service = new AresService($httpClient);
        $result = $service->loadByCompanyId('11678631');

        self::assertNull($result);
    }

    public function testLoadByCompanyIdReturnsResultWithoutVatId(): void
    {
        $responseBody = json_encode([
            'ico' => '12345678',
            'obchodniJmeno' => 'Test Company',
            'sidlo' => [
                'nazevUlice' => 'Testová',
                'cisloDomovni' => 123,
                'nazevObce' => 'Praha',
                'psc' => 11000,
            ],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody, ['http_code' => 200]);
        $httpClient = new MockHttpClient($mockResponse);

        $service = new AresService($httpClient);
        $result = $service->loadByCompanyId('12345678');

        self::assertNotNull($result);
        self::assertNull($result->companyVatId);
    }
}
