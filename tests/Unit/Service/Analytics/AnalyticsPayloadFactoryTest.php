<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Analytics;

use App\Service\Analytics\AnalyticsPayloadFactory;
use PHPUnit\Framework\TestCase;

/**
 * The money conversion is the whole point of this test.
 *
 * Prices are stored in haléře. Google expects major units. Shipping the raw
 * integer would report every conversion at 100× its value, and nothing in the
 * app would look wrong — only the agency's bidding would quietly go mad.
 */
final class AnalyticsPayloadFactoryTest extends TestCase
{
    /**
     * @return iterable<string, array{int, float}>
     */
    public static function amountProvider(): iterable
    {
        yield 'whole crowns' => [150000, 1500.0];
        yield 'with halere' => [149950, 1499.5];
        yield 'single crown' => [100, 1.0];
        yield 'sub-crown' => [50, 0.5];
        yield 'zero' => [0, 0.0];
        yield 'rounding to 2 decimals' => [133333, 1333.33];
    }

    /**
     * @param int   $inHaler  what the database stores
     * @param float $expected what Google must receive
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('amountProvider')]
    public function testAmountsAreConvertedFromHalereToCrowns(int $inHaler, float $expected): void
    {
        $factory = new AnalyticsPayloadFactory();

        $reflection = new \ReflectionMethod($factory, 'toMajorUnits');

        self::assertSame($expected, $reflection->invoke($factory, $inHaler));
    }
}
