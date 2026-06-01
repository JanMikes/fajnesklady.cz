<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\StorageMapImageGenerator;
use PHPUnit\Framework\TestCase;

class StorageMapImageGeneratorTest extends TestCase
{
    public function testRotationZeroProducesAxisAlignedCorners(): void
    {
        $corners = StorageMapImageGenerator::rotatedCorners(50, 50, 100, 100, 0);

        self::assertSame([[50, 50], [150, 50], [150, 150], [50, 150]], $corners);
    }

    public function testRotationNinetyRotatesCornersClockwise(): void
    {
        $corners = StorageMapImageGenerator::rotatedCorners(50, 50, 100, 100, 90);

        // Center (100, 100) preserved; corners are the 0° set rotated one position
        // clockwise (top-left lands where top-right was).
        self::assertSame([[150, 50], [150, 150], [50, 150], [50, 50]], $corners);
    }

    public function testRotationOneEightyMapsToOppositeCorners(): void
    {
        $corners = StorageMapImageGenerator::rotatedCorners(50, 50, 100, 100, 180);

        self::assertSame([[150, 150], [50, 150], [50, 50], [150, 50]], $corners);
    }

    public function testCenterIsInvariantUnderArbitraryRotation(): void
    {
        // Non-square rectangle, awkward angle: the mean of the four corners must
        // still land on the rectangle's center (within rounding tolerance).
        $corners = StorageMapImageGenerator::rotatedCorners(10, 20, 80, 40, 37);

        $meanX = array_sum(array_column($corners, 0)) / 4;
        $meanY = array_sum(array_column($corners, 1)) / 4;

        self::assertEqualsWithDelta(50.0, $meanX, 1.0);
        self::assertEqualsWithDelta(40.0, $meanY, 1.0);
    }
}
