<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Billing;

use App\Service\Billing\CzechDayCount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CzechDayCountTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function provideDayCounts(): iterable
    {
        yield 'one day' => [1, '1 den'];
        yield 'two days' => [2, '2 dny'];
        yield 'three days' => [3, '3 dny'];
        yield 'four days' => [4, '4 dny'];
        yield 'five days' => [5, '5 dní'];
        yield 'seven days' => [7, '7 dní'];
        yield 'thirty days' => [30, '30 dní'];
    }

    #[DataProvider('provideDayCounts')]
    public function testDaysInflectsCzechCounts(int $days, string $expected): void
    {
        self::assertSame($expected, CzechDayCount::days($days));
    }
}
