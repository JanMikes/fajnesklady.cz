<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Place;

use App\Entity\Place;
use App\Service\Place\PlaceAddressFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class PlaceAddressFormatterTest extends TestCase
{
    public function testFormatWithFullAddress(): void
    {
        $formatter = new PlaceAddressFormatter();

        self::assertSame(
            'Revolucni 1, 110 00 Praha',
            $formatter->format($this->place(address: 'Revolucni 1', city: 'Praha', postalCode: '110 00')),
        );
    }

    public function testFormatWithoutAddressFallsBackToPostalAndCity(): void
    {
        $formatter = new PlaceAddressFormatter();

        self::assertSame(
            '301 00 Plzen',
            $formatter->format($this->place(address: null, city: 'Plzen', postalCode: '301 00')),
        );
    }

    public function testFormatTrimsEmptyAddressDefensively(): void
    {
        $formatter = new PlaceAddressFormatter();

        self::assertSame(
            '301 00 Plzen',
            $formatter->format($this->place(address: '', city: 'Plzen', postalCode: '301 00')),
        );
    }

    public function testNavigationUrlPrefersCoordinatesWhenPresent(): void
    {
        $formatter = new PlaceAddressFormatter();
        $place = $this->place(address: null, city: 'Plzen', postalCode: '301 00');
        $place->updateLocation('49.7437572', '13.3799330', new \DateTimeImmutable());

        self::assertSame(
            'https://www.google.com/maps/dir/?api=1&destination=49.7437572,13.3799330',
            $formatter->navigationUrl($place),
        );
    }

    public function testNavigationUrlFallsBackToUrlEncodedAddressWhenNoCoords(): void
    {
        $formatter = new PlaceAddressFormatter();
        $place = $this->place(address: 'Revolucni 1', city: 'Praha', postalCode: '110 00');

        self::assertSame(
            'https://www.google.com/maps/dir/?api=1&destination=Revolucni%201%2C%20110%2000%20Praha',
            $formatter->navigationUrl($place),
        );
    }

    public function testNavigationUrlReturnsNullWhenNothingToRouteTo(): void
    {
        $formatter = new PlaceAddressFormatter();

        self::assertNull(
            $formatter->navigationUrl($this->place(address: null, city: 'Plzen', postalCode: '301 00')),
        );
    }

    public function testHasNavigationMirrorsNavigationUrl(): void
    {
        $formatter = new PlaceAddressFormatter();

        self::assertFalse($formatter->hasNavigation($this->place(address: null, city: 'Plzen', postalCode: '301 00')));
        self::assertTrue($formatter->hasNavigation($this->place(address: 'Revolucni 1', city: 'Praha', postalCode: '110 00')));

        $withCoords = $this->place(address: null, city: 'Plzen', postalCode: '301 00');
        $withCoords->updateLocation('49.7', '13.4', new \DateTimeImmutable());
        self::assertTrue($formatter->hasNavigation($withCoords));
    }

    private function place(?string $address, string $city, string $postalCode): Place
    {
        return new Place(
            id: Uuid::v7(),
            name: 'Test',
            address: $address,
            city: $city,
            postalCode: $postalCode,
            description: null,
            createdAt: new \DateTimeImmutable(),
        );
    }
}
