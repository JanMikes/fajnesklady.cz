<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\AresSubject;
use PHPUnit\Framework\TestCase;

class AresSubjectTest extends TestCase
{
    public function testFromArrayWithFullData(): void
    {
        $data = [
            'ico' => '27082440',
            'obchodniJmeno' => 'Alza.cz a.s.',
            'dic' => 'CZ27082440',
            'sidlo' => [
                'nazevUlice' => 'Jankovcova',
                'cisloDomovni' => 1522,
                'cisloOrientacni' => 53,
                'nazevObce' => 'Praha',
                'psc' => 17000,
            ],
        ];

        $subject = AresSubject::fromArray($data);

        self::assertSame('27082440', $subject->companyId);
        self::assertSame('Alza.cz a.s.', $subject->companyName);
        self::assertSame('CZ27082440', $subject->vatId);
        self::assertSame('Jankovcova', $subject->address->street);
    }

    public function testFromArrayWithoutVatId(): void
    {
        $data = [
            'ico' => '12345678',
            'obchodniJmeno' => 'Test Company',
            'sidlo' => [
                'nazevObce' => 'Praha',
                'psc' => 11000,
            ],
        ];

        $subject = AresSubject::fromArray($data);

        self::assertSame('12345678', $subject->companyId);
        self::assertSame('Test Company', $subject->companyName);
        self::assertNull($subject->vatId);
    }

    public function testFromArrayWithEmptySidlo(): void
    {
        $data = [
            'ico' => '12345678',
            'obchodniJmeno' => 'Test Company',
        ];

        $subject = AresSubject::fromArray($data);

        self::assertSame('12345678', $subject->companyId);
        self::assertNull($subject->address->street);
        self::assertNull($subject->address->city);
    }

    public function testToResultConvertsToAresResult(): void
    {
        $data = [
            'ico' => '11678631',
            'obchodniJmeno' => 'Mekmann s.r.o.',
            'dic' => 'CZ11678631',
            'sidlo' => [
                'nazevUlice' => 'Dvořákova',
                'cisloDomovni' => 780,
                'nazevObce' => 'Frýdlant nad Ostravicí',
                'psc' => 73911,
            ],
        ];

        $subject = AresSubject::fromArray($data);
        $result = $subject->toResult();

        self::assertSame('11678631', $result->companyId);
        self::assertSame('Mekmann s.r.o.', $result->companyName);
        self::assertSame('CZ11678631', $result->companyVatId);
        self::assertSame('Dvořákova 780', $result->street);
        self::assertSame('Frýdlant nad Ostravicí', $result->city);
        self::assertSame('73911', $result->postalCode);
    }
}
