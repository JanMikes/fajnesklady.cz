<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\StorageStatus;
use App\Exception\StorageNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class StorageRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(Storage $storage): void
    {
        $this->entityManager->persist($storage);
    }

    public function delete(Storage $storage): void
    {
        $this->entityManager->remove($storage);
    }

    public function get(Uuid $id): Storage
    {
        return $this->entityManager->find(Storage::class, $id)
            ?? throw StorageNotFound::withId($id);
    }

    public function find(Uuid $id): ?Storage
    {
        return $this->entityManager->find(Storage::class, $id);
    }

    /**
     * @return Storage[]
     */
    public function findByStorageType(StorageType $storageType): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->where('s.storageType = :storageType')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('storageType', $storageType)
            ->orderBy('s.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Effective monthly price range for all storages of a given type at a place.
     *
     * The place_detail page used to advertise `StorageType::defaultPricePerMonth`
     * unconditionally — but individual `Storage` units can override that via
     * `Storage::pricePerMonth`, and the order flow uses the per-unit effective
     * price. That mismatch confused customers seeing "1 400 Kč/měsíc" on the
     * map and then "1 800 Kč" in the order. Returning the [min, max] effective
     * range lets the detail page show truthful pricing across units.
     *
     * Returns null when there are no (non-deleted) storages of the type at the
     * place, so the caller falls back to the type default.
     *
     * @return array{min: int, max: int}|null halire (CZK × 100)
     */
    public function getEffectiveMonthlyPriceRangeForType(StorageType $storageType, Place $place): ?array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('COALESCE(s.pricePerMonth, st.defaultPricePerMonth) AS effective_price')
            ->from(Storage::class, 's')
            ->join('s.storageType', 'st')
            ->where('s.storageType = :storageType')
            ->andWhere('s.place = :place')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('storageType', $storageType)
            ->setParameter('place', $place)
            ->getQuery()
            ->getArrayResult();

        if ([] === $rows) {
            return null;
        }

        $prices = array_map(static fn (array $r): int => (int) $r['effective_price'], $rows);

        return ['min' => min($prices), 'max' => max($prices)];
    }

    /**
     * @return Storage[]
     */
    public function findByStorageTypeAndPlace(StorageType $storageType, Place $place): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->where('s.storageType = :storageType')
            ->andWhere('s.place = :place')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('storageType', $storageType)
            ->setParameter('place', $place)
            ->orderBy('s.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Storage[]
     */
    public function findByPlace(Place $place): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->where('s.place = :place')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('place', $place)
            ->orderBy('s.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Storage[]
     */
    public function findByPlaceWithoutLockCode(Place $place): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->where('s.place = :place')
            ->andWhere('s.deletedAt IS NULL')
            ->andWhere('s.lockCode IS NULL')
            ->setParameter('place', $place)
            ->orderBy('s.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return string[]
     */
    public function findActiveLockCodesByPlace(Place $place): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('s.lockCode')
            ->from(Storage::class, 's')
            ->where('s.place = :place')
            ->andWhere('s.deletedAt IS NULL')
            ->andWhere('s.lockCode IS NOT NULL')
            ->setParameter('place', $place)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r): string => (string) $r['lockCode'], $rows);
    }

    public function countByPlaceWithCodeExcludingStorage(Place $place, string $lockCode, Storage $storage): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Storage::class, 's')
            ->where('s.place = :place')
            ->andWhere('s.lockCode = :code')
            ->andWhere('s.deletedAt IS NULL')
            ->andWhere('s.id != :selfId')
            ->setParameter('place', $place)
            ->setParameter('code', $lockCode)
            ->setParameter('selfId', $storage->id)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return Storage[]
     */
    public function findByOwner(User $owner): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->where('s.owner = :owner')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('owner', $owner)
            ->orderBy('s.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Storage[]
     */
    public function findByOwnerAndPlace(User $owner, Place $place): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->where('s.owner = :owner')
            ->andWhere('s.place = :place')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('owner', $owner)
            ->setParameter('place', $place)
            ->orderBy('s.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Storage[]
     */
    public function findAllAvailable(): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->where('s.status = :status')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('status', StorageStatus::AVAILABLE)
            ->orderBy('s.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Storage[]
     */
    public function findAvailableByStorageType(StorageType $storageType): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->where('s.storageType = :storageType')
            ->andWhere('s.status = :status')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('storageType', $storageType)
            ->setParameter('status', StorageStatus::AVAILABLE)
            ->orderBy('s.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countByStorageType(StorageType $storageType): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Storage::class, 's')
            ->where('s.storageType = :storageType')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('storageType', $storageType)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countAvailableByStorageType(StorageType $storageType): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Storage::class, 's')
            ->where('s.storageType = :storageType')
            ->andWhere('s.status = :status')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('storageType', $storageType)
            ->setParameter('status', StorageStatus::AVAILABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByPlace(Place $place): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Storage::class, 's')
            ->where('s.place = :place')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('place', $place)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByOwnerAndPlace(User $owner, Place $place): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Storage::class, 's')
            ->where('s.owner = :owner')
            ->andWhere('s.place = :place')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('owner', $owner)
            ->setParameter('place', $place)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countAtPlace(Place $place, ?User $owner): int
    {
        return (int) $this->scopedAtPlace($place, $owner)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countOccupiedAtPlace(Place $place, ?User $owner): int
    {
        return (int) $this->scopedAtPlace($place, $owner)
            ->andWhere('s.status = :status')
            ->setParameter('status', StorageStatus::OCCUPIED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countAvailableAtPlace(Place $place, ?User $owner): int
    {
        return (int) $this->scopedAtPlace($place, $owner)
            ->andWhere('s.status = :status')
            ->setParameter('status', StorageStatus::AVAILABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countBlockedAtPlace(Place $place, ?User $owner): int
    {
        return (int) $this->scopedAtPlace($place, $owner)
            ->andWhere('s.status = :status')
            ->setParameter('status', StorageStatus::MANUALLY_UNAVAILABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * True iff there's ≥1 non-deleted storage at $place owned by someone other
     * than $excludeOwner (and whose owner is set). Surfaces a co-owner
     * disclaimer on the landlord dashboard.
     */
    public function hasCoOwners(Place $place, User $excludeOwner): bool
    {
        $count = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Storage::class, 's')
            ->where('s.place = :place')
            ->andWhere('s.deletedAt IS NULL')
            ->andWhere('s.owner IS NOT NULL')
            ->andWhere('s.owner != :owner')
            ->setParameter('place', $place)
            ->setParameter('owner', $excludeOwner)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    private function scopedAtPlace(Place $place, ?User $owner): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Storage::class, 's')
            ->where('s.place = :place')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('place', $place);

        if (null !== $owner) {
            $qb->andWhere('s.owner = :owner')->setParameter('owner', $owner);
        }

        return $qb;
    }

    public function countByOwner(User $owner): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Storage::class, 's')
            ->where('s.owner = :owner')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countOccupiedByOwner(User $owner): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Storage::class, 's')
            ->where('s.owner = :owner')
            ->andWhere('s.status = :status')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('owner', $owner)
            ->setParameter('status', StorageStatus::OCCUPIED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countAvailableByOwner(User $owner): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Storage::class, 's')
            ->where('s.owner = :owner')
            ->andWhere('s.status = :status')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('owner', $owner)
            ->setParameter('status', StorageStatus::AVAILABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countBlockedByOwner(User $owner): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Storage::class, 's')
            ->where('s.owner = :owner')
            ->andWhere('s.status = :status')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('owner', $owner)
            ->setParameter('status', StorageStatus::MANUALLY_UNAVAILABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function hasOrdersOrContracts(Storage $storage): bool
    {
        // Check for orders
        $orderCount = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(o.id)')
            ->from('App\Entity\Order', 'o')
            ->where('o.storage = :storage')
            ->setParameter('storage', $storage)
            ->getQuery()
            ->getSingleScalarResult();

        if ($orderCount > 0) {
            return true;
        }

        // Check for contracts
        $contractCount = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from('App\Entity\Contract', 'c')
            ->where('c.storage = :storage')
            ->setParameter('storage', $storage)
            ->getQuery()
            ->getSingleScalarResult();

        return $contractCount > 0;
    }

    /**
     * @return Storage[]
     */
    public function findAll(): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->where('s.deletedAt IS NULL')
            ->orderBy('s.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Storage[]
     */
    public function findAllPaginated(int $page, int $limit): array
    {
        $offset = ($page - 1) * $limit;

        return $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->where('s.deletedAt IS NULL')
            ->orderBy('s.createdAt', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countTotal(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Storage::class, 's')
            ->where('s.deletedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countOccupied(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Storage::class, 's')
            ->where('s.status = :status')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('status', StorageStatus::OCCUPIED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Aggregate storage counts for the given place IDs in a single DBAL query.
     *
     * Mirrors the per-place counters in {@see self::countAtPlace()},
     * {@see self::countOccupiedAtPlace()}, {@see self::countAvailableAtPlace()}
     * — used by the admin places export to avoid 3 queries per row.
     *
     * Places without any storages are absent from the result; callers default
     * to zeros.
     *
     * @param Uuid[] $placeIds
     *
     * @return array<string, array{total: int, occupied: int, available: int}>
     */
    public function loadStorageStatsByPlaceIds(array $placeIds): array
    {
        if ([] === $placeIds) {
            return [];
        }

        $idStrings = array_map(static fn (Uuid $id): string => (string) $id, $placeIds);

        $rows = $this->entityManager->getConnection()->executeQuery(
            <<<'SQL'
                SELECT
                    s.place_id::text AS place_id,
                    COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE s.status = :occupied) AS occupied,
                    COUNT(*) FILTER (WHERE s.status = :available) AS available
                FROM storage s
                WHERE s.place_id IN (:placeIds)
                  AND s.deleted_at IS NULL
                GROUP BY s.place_id
                SQL,
            [
                'placeIds' => $idStrings,
                'occupied' => StorageStatus::OCCUPIED->value,
                'available' => StorageStatus::AVAILABLE->value,
            ],
            [
                'placeIds' => \Doctrine\DBAL\ArrayParameterType::STRING,
            ]
        )->fetchAllAssociative();

        $stats = [];
        foreach ($rows as $row) {
            $stats[(string) $row['place_id']] = [
                'total' => (int) $row['total'],
                'occupied' => (int) $row['occupied'],
                'available' => (int) $row['available'],
            ];
        }

        return $stats;
    }

    /**
     * Distinct storage owners for the given places, keyed by RFC-4122 place
     * UUID string. Each entry is a list of {fullName, email} pairs (one per
     * unique owner of any non-deleted storage at that place), ordered by name
     * for stable export output. Single DBAL query.
     *
     * @param Uuid[] $placeIds
     *
     * @return array<string, list<array{fullName: string, email: string}>>
     */
    public function loadOwnersByPlaceIds(array $placeIds): array
    {
        if ([] === $placeIds) {
            return [];
        }

        $idStrings = array_map(static fn (Uuid $id): string => (string) $id, $placeIds);

        $rows = $this->entityManager->getConnection()->executeQuery(
            <<<'SQL'
                SELECT DISTINCT
                    s.place_id::text AS place_id,
                    u.first_name AS first_name,
                    u.last_name AS last_name,
                    u.email AS email
                FROM storage s
                INNER JOIN users u ON u.id = s.owner_id
                WHERE s.place_id IN (:placeIds)
                  AND s.deleted_at IS NULL
                ORDER BY u.last_name ASC, u.first_name ASC
                SQL,
            ['placeIds' => $idStrings],
            ['placeIds' => \Doctrine\DBAL\ArrayParameterType::STRING]
        )->fetchAllAssociative();

        $owners = [];
        foreach ($rows as $row) {
            $placeId = (string) $row['place_id'];
            $owners[$placeId] ??= [];
            $owners[$placeId][] = [
                'fullName' => trim(((string) $row['first_name']).' '.((string) $row['last_name'])),
                'email' => (string) $row['email'],
            ];
        }

        return $owners;
    }

    /**
     * Find storages with optional filtering by owner, place, and storage type.
     *
     * @return Storage[]
     */
    public function findFiltered(?User $owner, ?Place $place, ?StorageType $storageType): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->where('s.deletedAt IS NULL');

        if (null !== $owner) {
            $qb->andWhere('s.owner = :owner')
                ->setParameter('owner', $owner);
        }

        if (null !== $place) {
            $qb->andWhere('s.place = :place')
                ->setParameter('place', $place);
        }

        if (null !== $storageType) {
            $qb->andWhere('s.storageType = :storageType')
                ->setParameter('storageType', $storageType);
        }

        return $qb->orderBy('s.number', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
