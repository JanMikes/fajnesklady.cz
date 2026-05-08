<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Place;
use App\Entity\PlaceStorageCodeUsage;
use App\Entity\Storage;
use App\Exception\InvalidStorageCode;
use App\Exception\StorageCodeRangeExhausted;
use App\Repository\PlaceStorageCodeUsageRepository;
use App\Repository\StorageRepository;
use App\Service\Identity\ProvideIdentity;
use Psr\Clock\ClockInterface;

final readonly class StorageCodeGenerator
{
    public function __construct(
        private StorageRepository $storageRepository,
        private PlaceStorageCodeUsageRepository $usageRepository,
        private ProvideIdentity $identityProvider,
        private ClockInterface $clock,
    ) {
    }

    public function format(Place $place, int $value): string
    {
        return str_pad((string) $value, $place->storageCodeDigits, '0', STR_PAD_LEFT);
    }

    /**
     * @param array<string, true> $reserved Codes already chosen in this batch but not yet flushed
     *
     * @throws StorageCodeRangeExhausted when no available code exists
     */
    public function propose(Place $place, array $reserved = []): string
    {
        $used = $this->buildUsedSet($place);
        foreach ($reserved as $code => $_) {
            $used[(string) $code] = true;
        }

        if ($this->countUsedInRange($place, $used) >= $place->storageCodeRangeSize()) {
            throw StorageCodeRangeExhausted::forPlace($place);
        }

        $maxAttempts = max(100, $place->storageCodeRangeSize() * 4);
        for ($i = 0; $i < $maxAttempts; ++$i) {
            $candidate = random_int($place->storageCodeFrom, $place->storageCodeTo);
            $formatted = $this->format($place, $candidate);
            if (!isset($used[$formatted])) {
                return $formatted;
            }
        }

        throw StorageCodeRangeExhausted::forPlace($place);
    }

    /**
     * @throws InvalidStorageCode
     */
    public function validateForStorage(Place $place, Storage $storage, string $code): void
    {
        if (strlen($code) !== $place->storageCodeDigits) {
            throw InvalidStorageCode::wrongLength($place->storageCodeDigits);
        }

        if (!ctype_digit($code)) {
            throw InvalidStorageCode::notNumeric();
        }

        $value = (int) $code;
        if ($value < $place->storageCodeFrom || $value > $place->storageCodeTo) {
            throw InvalidStorageCode::outOfRange($place->storageCodeFrom, $place->storageCodeTo);
        }

        if ($this->storageRepository->countByPlaceWithCodeExcludingStorage($place, $code, $storage) > 0) {
            throw InvalidStorageCode::alreadyUsedByAnotherStorage($code);
        }

        if ($storage->lockCode !== $code && $this->usageRepository->existsForPlace($place, $code)) {
            throw InvalidStorageCode::inHistory($code);
        }
    }

    public function markUsed(Place $place, string $code): void
    {
        if ($this->usageRepository->existsForPlace($place, $code)) {
            return;
        }

        $usage = new PlaceStorageCodeUsage(
            id: $this->identityProvider->next(),
            place: $place,
            code: $code,
            usedAt: $this->clock->now(),
        );
        $this->usageRepository->save($usage);
    }

    /**
     * Fills every non-deleted Storage with NULL lockCode at the place with a
     * fresh proposed code, persists it, and records usage.
     *
     * @return array<int, array{storage: Storage, code: string}>
     *
     * @throws StorageCodeRangeExhausted when the range runs out mid-bulk
     */
    public function bulkGenerateForEmpty(Place $place): array
    {
        $now = $this->clock->now();
        $filled = [];
        $reserved = [];

        foreach ($this->storageRepository->findByPlaceWithoutLockCode($place) as $storage) {
            $code = $this->propose($place, $reserved);
            $reserved[$code] = true;

            $storage->updateLockCode($code, $now);
            $this->storageRepository->save($storage);
            $this->markUsed($place, $code);
            $filled[] = ['storage' => $storage, 'code' => $code];
        }

        return $filled;
    }

    public function availableCount(Place $place): int
    {
        $used = $this->buildUsedSet($place);

        return max(0, $place->storageCodeRangeSize() - $this->countUsedInRange($place, $used));
    }

    /**
     * @return array<string, true>
     */
    private function buildUsedSet(Place $place): array
    {
        $set = [];
        foreach ($this->usageRepository->findCodesForPlace($place) as $code) {
            $set[$code] = true;
        }
        foreach ($this->storageRepository->findActiveLockCodesByPlace($place) as $code) {
            $set[$code] = true;
        }

        return $set;
    }

    /**
     * @param array<string, mixed> $used
     */
    private function countUsedInRange(Place $place, array $used): int
    {
        $count = 0;
        foreach (array_keys($used) as $code) {
            $code = (string) $code;
            if (!ctype_digit($code)) {
                continue;
            }
            $value = (int) $code;
            if ($value >= $place->storageCodeFrom && $value <= $place->storageCodeTo) {
                ++$count;
            }
        }

        return $count;
    }
}
