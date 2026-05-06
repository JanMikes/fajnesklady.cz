<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Place;
use App\Entity\PlaceStorageCodeUsage;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Exception\InvalidStorageCode;
use App\Exception\StorageCodeRangeExhausted;
use App\Repository\PlaceStorageCodeUsageRepository;
use App\Repository\StorageRepository;
use App\Service\StorageCodeGenerator;
use App\Tests\Support\PredictableIdentityProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

class StorageCodeGeneratorTest extends TestCase
{
    private MockClock $clock;
    private PredictableIdentityProvider $identity;
    /** @var array<string, true> map of (place_id . ':' . code) => true */
    private array $usageStore = [];
    /** @var array<int, string> codes returned for findActiveLockCodesByPlace */
    private array $activeCodesStore = [];
    /** @var Storage[] */
    private array $emptyStorages = [];

    protected function setUp(): void
    {
        $this->clock = new MockClock('2025-06-15 12:00:00 UTC');
        $this->identity = new PredictableIdentityProvider();
        $this->usageStore = [];
        $this->activeCodesStore = [];
        $this->emptyStorages = [];
    }

    public function testFormatPadsToConfiguredDigits(): void
    {
        $generator = $this->createGenerator();
        $place = $this->createPlace(digits: 4);

        $this->assertSame('0005', $generator->format($place, 5));
        $this->assertSame('0042', $generator->format($place, 42));
        $this->assertSame('9999', $generator->format($place, 9999));

        $place8 = $this->createPlace(digits: 8, to: 99_999_999);
        $this->assertSame('00000123', $generator->format($place8, 123));
    }

    public function testProposeReturnsCodeInRangeAndNotInUsedSet(): void
    {
        $generator = $this->createGenerator();
        $place = $this->createPlace();

        $code = $generator->propose($place);

        $this->assertSame(4, strlen($code));
        $this->assertTrue(ctype_digit($code));
        $value = (int) $code;
        $this->assertGreaterThanOrEqual(0, $value);
        $this->assertLessThanOrEqual(9999, $value);
    }

    public function testProposeAvoidsCodesInUsage(): void
    {
        $place = $this->createPlace(digits: 1, from: 0, to: 1);
        $this->usageStore[$place->id->toRfc4122().':0'] = true;
        $generator = $this->createGenerator();

        // Only "1" is available
        for ($i = 0; $i < 20; ++$i) {
            $this->assertSame('1', $generator->propose($place));
        }
    }

    public function testProposeAvoidsActiveLockCodes(): void
    {
        $place = $this->createPlace(digits: 1, from: 0, to: 1);
        $this->activeCodesStore = ['0'];
        $generator = $this->createGenerator();

        $this->assertSame('1', $generator->propose($place));
    }

    public function testProposeThrowsWhenRangeExhausted(): void
    {
        $place = $this->createPlace(digits: 1, from: 0, to: 1);
        $this->activeCodesStore = ['0', '1'];
        $generator = $this->createGenerator();

        $this->expectException(StorageCodeRangeExhausted::class);
        $generator->propose($place);
    }

    public function testValidateRejectsWrongLength(): void
    {
        $generator = $this->createGenerator();
        $place = $this->createPlace();
        $storage = $this->createStorage($place, lockCode: null);

        $this->expectException(InvalidStorageCode::class);
        $this->expectExceptionMessage('4 číslic');

        $generator->validateForStorage($place, $storage, '12');
    }

    public function testValidateRejectsNonNumeric(): void
    {
        $generator = $this->createGenerator();
        $place = $this->createPlace();
        $storage = $this->createStorage($place, lockCode: null);

        $this->expectException(InvalidStorageCode::class);
        $this->expectExceptionMessage('pouze číslice');

        $generator->validateForStorage($place, $storage, 'ABCD');
    }

    public function testValidateRejectsOutOfRange(): void
    {
        $generator = $this->createGenerator();
        $place = $this->createPlace(digits: 4, from: 1000, to: 2000);
        $storage = $this->createStorage($place, lockCode: null);

        $this->expectException(InvalidStorageCode::class);
        $this->expectExceptionMessage('rozsahu 1000 až 2000');

        $generator->validateForStorage($place, $storage, '0500');
    }

    public function testValidateRejectsCodeUsedByAnotherStorage(): void
    {
        $place = $this->createPlace();
        $storage = $this->createStorage($place, lockCode: null);
        // Simulate another storage already using the code
        $this->emptyStorages = [];
        $generator = $this->createGenerator(otherStorageCount: 1);

        $this->expectException(InvalidStorageCode::class);
        $this->expectExceptionMessage('jinému skladu');

        $generator->validateForStorage($place, $storage, '0042');
    }

    public function testValidateRejectsCodeInHistory(): void
    {
        $place = $this->createPlace();
        $storage = $this->createStorage($place, lockCode: null);
        $this->usageStore[$place->id->toRfc4122().':0042'] = true;
        $generator = $this->createGenerator();

        $this->expectException(InvalidStorageCode::class);
        $this->expectExceptionMessage('v minulosti');

        $generator->validateForStorage($place, $storage, '0042');
    }

    public function testValidateAcceptsStorageOwnCurrentCodeEvenIfInHistory(): void
    {
        $place = $this->createPlace();
        $storage = $this->createStorage($place, lockCode: '0042');
        $this->usageStore[$place->id->toRfc4122().':0042'] = true;
        $generator = $this->createGenerator();

        $generator->validateForStorage($place, $storage, '0042');

        $this->assertSame('0042', $storage->lockCode);
    }

    public function testValidateAcceptsValidCode(): void
    {
        $place = $this->createPlace();
        $storage = $this->createStorage($place, lockCode: null);
        $generator = $this->createGenerator();

        $generator->validateForStorage($place, $storage, '0042');

        // Reaching this point without exception is the assertion.
        $this->assertNull($storage->lockCode);
    }

    public function testMarkUsedIsIdempotent(): void
    {
        $place = $this->createPlace();
        $generator = $this->createGenerator();

        $generator->markUsed($place, '0042');
        $generator->markUsed($place, '0042');

        $this->assertCount(1, array_keys($this->usageStore));
    }

    private function createGenerator(int $otherStorageCount = 0): StorageCodeGenerator
    {
        $usageStore = &$this->usageStore;
        $activeCodesStore = &$this->activeCodesStore;
        $emptyStorages = &$this->emptyStorages;

        $usageRepository = new class ($usageStore) extends PlaceStorageCodeUsageRepository {
            /** @param array<string, true> $store */
            public function __construct(private array &$store)
            {
            }

            public function save(PlaceStorageCodeUsage $usage): void
            {
                $key = $usage->place->id->toRfc4122().':'.$usage->code;
                $this->store[$key] = true;
            }

            public function existsForPlace(Place $place, string $code): bool
            {
                return isset($this->store[$place->id->toRfc4122().':'.$code]);
            }

            public function findCodesForPlace(Place $place): array
            {
                $prefix = $place->id->toRfc4122().':';
                $result = [];
                foreach (array_keys($this->store) as $key) {
                    if (str_starts_with($key, $prefix)) {
                        $result[] = substr($key, strlen($prefix));
                    }
                }

                return $result;
            }

            public function releaseUnusedForPlace(Place $place): int
            {
                throw new \RuntimeException('Not used in unit tests.');
            }
        };

        $storageRepository = new class ($activeCodesStore, $emptyStorages, $otherStorageCount) extends StorageRepository {
            /**
             * @param string[]  $activeCodes
             * @param Storage[] $emptyStorages
             */
            public function __construct(
                private array &$activeCodes,
                private array &$emptyStorages,
                private int $otherStorageCount,
            ) {
            }

            public function findActiveLockCodesByPlace(Place $place): array
            {
                return $this->activeCodes;
            }

            public function findByPlaceWithoutLockCode(Place $place): array
            {
                return $this->emptyStorages;
            }

            public function countByPlaceWithCodeExcludingStorage(Place $place, string $lockCode, Storage $storage): int
            {
                return $this->otherStorageCount;
            }

            public function save(Storage $storage): void
            {
                // No-op for unit tests.
            }
        };

        return new StorageCodeGenerator(
            $storageRepository,
            $usageRepository,
            $this->identity,
            $this->clock,
        );
    }

    private function createPlace(int $digits = 4, int $from = 0, int $to = 9999): Place
    {
        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: $this->clock->now(),
        );
        $place->updateStorageCodeConfig(true, $digits, $from, $to, $this->clock->now());

        return $place;
    }

    private function createStorage(Place $place, ?string $lockCode): Storage
    {
        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Test Type',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            createdAt: $this->clock->now(),
        );

        $storage = new Storage(
            id: Uuid::v7(),
            number: 'T1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: $this->clock->now(),
        );

        if (null !== $lockCode) {
            $storage->updateLockCode($lockCode, $this->clock->now());
        }

        return $storage;
    }
}
