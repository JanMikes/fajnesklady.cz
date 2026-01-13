<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\AresAddress;
use PHPUnit\Framework\TestCase;

class AresAddressTest extends TestCase
{
    public function testFromArrayWithFullData(): void
    {
        $data = [
            'nazevUlice' => 'Jankovcova',
            'cisloDomovni' => 1522,
            'cisloOrientacni' => 53,
            'nazevObce' => 'Praha',
            'nazevMestskeCastiObvodu' => 'Praha 7',
            'psc' => 17000,
            'textovaAdresa' => 'Jankovcova 1522/53, Praha 7',
        ];

        $address = AresAddress::fromArray($data);

        self::assertSame('Jankovcova', $address->street);
        self::assertSame(1522, $address->houseNumber);
        self::assertSame(53, $address->orientationNumber);
        self::assertSame('Praha', $address->city);
        self::assertSame('Praha 7', $address->cityDistrict);
        self::assertSame('17000', $address->postalCode);
        self::assertSame('Jankovcova 1522/53, Praha 7', $address->textAddress);
    }

    public function testFromArrayWithPartialData(): void
    {
        $data = [
            'nazevObce' => 'Frýdlant',
            'cisloDomovni' => 780,
            'psc' => 73911,
        ];

        $address = AresAddress::fromArray($data);

        self::assertNull($address->street);
        self::assertSame(780, $address->houseNumber);
        self::assertNull($address->orientationNumber);
        self::assertSame('Frýdlant', $address->city);
        self::assertSame('73911', $address->postalCode);
    }

    public function testFromArrayWithEmptyData(): void
    {
        $address = AresAddress::fromArray([]);

        self::assertNull($address->street);
        self::assertNull($address->houseNumber);
        self::assertNull($address->orientationNumber);
        self::assertNull($address->city);
        self::assertNull($address->postalCode);
    }

    public function testFormatStreetWithStreetAndNumbers(): void
    {
        $address = new AresAddress(
            street: 'Jankovcova',
            houseNumber: 1522,
            orientationNumber: 53,
            city: 'Praha',
            cityDistrict: 'Praha 7',
            postalCode: '17000',
            textAddress: null,
        );

        self::assertSame('Jankovcova 1522/53', $address->formatStreet());
    }

    public function testFormatStreetWithOnlyHouseNumber(): void
    {
        $address = new AresAddress(
            street: 'Dvořákova',
            houseNumber: 780,
            orientationNumber: null,
            city: 'Frýdlant',
            cityDistrict: null,
            postalCode: '73911',
            textAddress: null,
        );

        self::assertSame('Dvořákova 780', $address->formatStreet());
    }

    public function testFormatStreetFallsBackToCityWhenNoStreet(): void
    {
        $address = new AresAddress(
            street: null,
            houseNumber: 123,
            orientationNumber: null,
            city: 'Vesnice',
            cityDistrict: null,
            postalCode: '12345',
            textAddress: null,
        );

        self::assertSame('Vesnice 123', $address->formatStreet());
    }

    public function testFormatCityReturnsCity(): void
    {
        $address = new AresAddress(
            street: 'Test',
            houseNumber: 1,
            orientationNumber: null,
            city: 'Praha',
            cityDistrict: 'Praha 7',
            postalCode: '17000',
            textAddress: null,
        );

        self::assertSame('Praha', $address->formatCity());
    }

    public function testFormatCityFallsBackToCityDistrict(): void
    {
        $address = new AresAddress(
            street: 'Test',
            houseNumber: 1,
            orientationNumber: null,
            city: null,
            cityDistrict: 'Praha 7',
            postalCode: '17000',
            textAddress: null,
        );

        self::assertSame('Praha 7', $address->formatCity());
    }

    public function testFormatPostalCode(): void
    {
        $address = new AresAddress(
            street: 'Test',
            houseNumber: 1,
            orientationNumber: null,
            city: 'Praha',
            cityDistrict: null,
            postalCode: '17000',
            textAddress: null,
        );

        self::assertSame('17000', $address->formatPostalCode());
    }

    public function testFormatPostalCodeReturnsEmptyStringWhenNull(): void
    {
        $address = new AresAddress(
            street: 'Test',
            houseNumber: 1,
            orientationNumber: null,
            city: 'Praha',
            cityDistrict: null,
            postalCode: null,
            textAddress: null,
        );

        self::assertSame('', $address->formatPostalCode());
    }
}
