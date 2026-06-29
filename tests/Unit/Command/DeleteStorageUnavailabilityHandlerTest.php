<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\DeleteStorageUnavailabilityCommand;
use App\Command\DeleteStorageUnavailabilityHandler;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\StorageUnavailability;
use App\Entity\User;
use App\Enum\StorageStatus;
use App\Repository\StorageUnavailabilityRepository;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

class DeleteStorageUnavailabilityHandlerTest extends TestCase
{
    private StorageUnavailabilityRepository&Stub $unavailabilityRepository;
    private ClockInterface&Stub $clock;
    private DeleteStorageUnavailabilityHandler $handler;

    private Storage $storage;
    private User $user;
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->unavailabilityRepository = $this->createStub(StorageUnavailabilityRepository::class);
        $this->clock = $this->createStub(ClockInterface::class);

        $this->today = new \DateTimeImmutable('2025-06-15 12:00:00');
        $this->clock->method('now')->willReturn($this->today);

        $this->handler = new DeleteStorageUnavailabilityHandler(
            $this->unavailabilityRepository,
            $this->clock,
        );

        $this->user = new User(Uuid::v7(), 'landlord@example.com', 'password', 'Test', 'User', $this->today);

        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: $this->today,
        );

        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Small Box',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            defaultPricePerMonthLongTerm: 35000,
            defaultPricePerYear: 35000 * 12,
            createdAt: $this->today,
        );

        $this->storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: $this->today,
            owner: $this->user,
        );
    }

    /**
     * The regression: a bounded block that has already lapsed (no longer active
     * "today") left the unit stuck `manually_unavailable`. Deleting it MUST
     * release the unit — the old code skipped release for inactive blocks.
     */
    public function testDeletingAnExpiredBlockReleasesTheStuckStorage(): void
    {
        $this->storage->markUnavailable($this->today->modify('-10 days'));
        self::assertSame(StorageStatus::MANUALLY_UNAVAILABLE, $this->storage->status);

        $expiredBlock = $this->makeBlock(
            startDate: $this->today->modify('-10 days'),
            endDate: $this->today->modify('-3 days'),
        );

        $this->unavailabilityRepository->method('find')->willReturn($expiredBlock);
        // No other blocks remain active today.
        $this->unavailabilityRepository->method('findActiveByStorageOnDate')->willReturn([]);

        ($this->handler)(new DeleteStorageUnavailabilityCommand($expiredBlock->id));

        self::assertSame(StorageStatus::AVAILABLE, $this->storage->status);
    }

    /**
     * The just-removed row is not flushed yet, so the "active blocks today" query
     * can still return it. The handler must exclude it by id and still release.
     */
    public function testDeletingAnActiveBlockReleasesEvenWhenQueryStillReturnsIt(): void
    {
        $this->storage->markUnavailable($this->today);
        $activeBlock = $this->makeBlock(
            startDate: $this->today->modify('-2 days'),
            endDate: $this->today->modify('+2 days'),
        );

        $this->unavailabilityRepository->method('find')->willReturn($activeBlock);
        // Pre-flush staleness: the soon-to-be-deleted block is still returned.
        $this->unavailabilityRepository->method('findActiveByStorageOnDate')->willReturn([$activeBlock]);

        ($this->handler)(new DeleteStorageUnavailabilityCommand($activeBlock->id));

        self::assertSame(StorageStatus::AVAILABLE, $this->storage->status);
    }

    /**
     * When ANOTHER block still covers today, the unit must stay blocked.
     */
    public function testDeletingOneOfTwoOverlappingBlocksKeepsItBlocked(): void
    {
        $this->storage->markUnavailable($this->today);
        $deletedBlock = $this->makeBlock(
            startDate: $this->today->modify('-2 days'),
            endDate: $this->today->modify('+2 days'),
        );
        $remainingBlock = $this->makeBlock(
            startDate: $this->today->modify('-1 day'),
            endDate: $this->today->modify('+5 days'),
        );

        $this->unavailabilityRepository->method('find')->willReturn($deletedBlock);
        $this->unavailabilityRepository->method('findActiveByStorageOnDate')->willReturn([$deletedBlock, $remainingBlock]);

        ($this->handler)(new DeleteStorageUnavailabilityCommand($deletedBlock->id));

        self::assertSame(StorageStatus::MANUALLY_UNAVAILABLE, $this->storage->status);
    }

    public function testMissingUnavailabilityIsANoOp(): void
    {
        $this->storage->markUnavailable($this->today);
        $this->unavailabilityRepository->method('find')->willReturn(null);

        ($this->handler)(new DeleteStorageUnavailabilityCommand(Uuid::v7()));

        // Nothing to delete → status untouched.
        self::assertSame(StorageStatus::MANUALLY_UNAVAILABLE, $this->storage->status);
    }

    private function makeBlock(\DateTimeImmutable $startDate, ?\DateTimeImmutable $endDate): StorageUnavailability
    {
        return new StorageUnavailability(
            id: Uuid::v7(),
            storage: $this->storage,
            startDate: $startDate,
            endDate: $endDate,
            reason: 'Údržba',
            createdBy: $this->user,
            createdAt: $startDate,
        );
    }
}
