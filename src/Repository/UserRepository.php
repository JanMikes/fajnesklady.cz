<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\User;
use App\Enum\UserRole;
use App\Exception\UserNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class UserRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ContractRepository $contractRepository,
    ) {
    }

    public function save(User $user): void
    {
        $this->entityManager->persist($user);
    }

    public function find(Uuid $id): ?User
    {
        return $this->entityManager->find(User::class, $id);
    }

    public function get(Uuid $id): User
    {
        return $this->find($id) ?? throw UserNotFound::withId($id);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return User[]
     */
    public function findAll(): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->orderBy('u.createdAt', 'DESC')
            ->addOrderBy('u.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return User[]
     */
    public function findAllPaginated(int $page, int $limit): array
    {
        $offset = ($page - 1) * $limit;

        return $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->orderBy('u.createdAt', 'DESC')
            ->addOrderBy('u.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countTotal(): int
    {
        $connection = $this->entityManager->getConnection();
        $result = $connection->executeQuery('SELECT COUNT(id) FROM users')->fetchOne();

        return (int) $result;
    }

    public function countVerified(): int
    {
        $connection = $this->entityManager->getConnection();
        $result = $connection->executeQuery(
            'SELECT COUNT(id) FROM users WHERE is_verified = :isVerified',
            ['isVerified' => true],
            ['isVerified' => \Doctrine\DBAL\Types\Types::BOOLEAN]
        )->fetchOne();

        return (int) $result;
    }

    public function countByRole(string $role): int
    {
        $connection = $this->entityManager->getConnection();
        $result = $connection->executeQuery(
            'SELECT COUNT(id) FROM users WHERE roles::jsonb @> :role::jsonb',
            ['role' => json_encode([$role])],
            ['role' => \Doctrine\DBAL\Types\Types::STRING]
        )->fetchOne();

        return (int) $result;
    }

    /**
     * Find all users with a specific role.
     *
     * @return User[]
     */
    public function findByRole(UserRole $role): array
    {
        $connection = $this->entityManager->getConnection();
        $ids = $connection->executeQuery(
            'SELECT id FROM users WHERE roles::jsonb @> :role::jsonb',
            ['role' => json_encode([$role->value])],
            ['role' => \Doctrine\DBAL\Types\Types::STRING]
        )->fetchFirstColumn();

        if (0 === count($ids)) {
            return [];
        }

        return $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find landlords eligible for self-billing (landlords who are not admins).
     *
     * @return User[]
     */
    public function findLandlordsForSelfBilling(): array
    {
        $connection = $this->entityManager->getConnection();

        // Find users who are landlords but NOT admins
        $ids = $connection->executeQuery(
            'SELECT id FROM users
             WHERE roles::jsonb @> :landlordRole::jsonb
             AND NOT (roles::jsonb @> :adminRole::jsonb)',
            [
                'landlordRole' => json_encode([UserRole::LANDLORD->value]),
                'adminRole' => json_encode([UserRole::ADMIN->value]),
            ],
            [
                'landlordRole' => \Doctrine\DBAL\Types\Types::STRING,
                'adminRole' => \Doctrine\DBAL\Types\Types::STRING,
            ]
        )->fetchFirstColumn();

        if (0 === count($ids)) {
            return [];
        }

        return $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('u.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return User[]
     */
    public function findOverduePaginated(int $page, int $limit, \DateTimeImmutable $now): array
    {
        $offset = ($page - 1) * $limit;

        return $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.id IN (:overdueIds)')
            ->setParameter('overdueIds', $this->overdueUserIdsSubquery($now))
            ->orderBy('u.createdAt', 'DESC')
            ->addOrderBy('u.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countOverdueUsers(\DateTimeImmutable $now): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(User::class, 'u')
            ->where('u.id IN (:overdueIds)')
            ->setParameter('overdueIds', $this->overdueUserIdsSubquery($now))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return User[]
     */
    public function findOnboardedPaginated(int $page, int $limit): array
    {
        $offset = ($page - 1) * $limit;

        return $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.id IN (:onboardedIds)')
            ->setParameter('onboardedIds', $this->onboardedUserIdsSubquery())
            ->orderBy('u.createdAt', 'DESC')
            ->addOrderBy('u.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countOnboarded(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(User::class, 'u')
            ->where('u.id IN (:onboardedIds)')
            ->setParameter('onboardedIds', $this->onboardedUserIdsSubquery())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return User[]
     */
    public function findWithActiveContractsPaginated(int $page, int $limit, \DateTimeImmutable $now): array
    {
        $offset = ($page - 1) * $limit;

        return $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.id IN (:activeIds)')
            ->setParameter('activeIds', $this->contractRepository->findActiveContractUserIdsSubquery($now))
            ->orderBy('u.createdAt', 'DESC')
            ->addOrderBy('u.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countWithActiveContracts(\DateTimeImmutable $now): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(User::class, 'u')
            ->where('u.id IN (:activeIds)')
            ->setParameter('activeIds', $this->contractRepository->findActiveContractUserIdsSubquery($now))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return User[]
     */
    public function findWithoutActiveContractsPaginated(int $page, int $limit, \DateTimeImmutable $now): array
    {
        $offset = ($page - 1) * $limit;

        return $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.id NOT IN (:activeIds)')
            ->setParameter('activeIds', $this->contractRepository->findActiveContractUserIdsSubquery($now))
            ->orderBy('u.createdAt', 'DESC')
            ->addOrderBy('u.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countWithoutActiveContracts(\DateTimeImmutable $now): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(User::class, 'u')
            ->where('u.id NOT IN (:activeIds)')
            ->setParameter('activeIds', $this->contractRepository->findActiveContractUserIdsSubquery($now))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Streamed iteration for export. Mirrors {@see self::findAllPaginated()},
     * {@see self::findOverduePaginated()}, {@see self::findOnboardedPaginated()},
     * {@see self::findWithActiveContractsPaginated()}, and
     * {@see self::findWithoutActiveContractsPaginated()} but without pagination.
     *
     * @return iterable<User>
     */
    public function streamForExport(?string $filter, \DateTimeImmutable $now): iterable
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->orderBy('u.createdAt', 'DESC')
            ->addOrderBy('u.id', 'DESC');

        switch ($filter) {
            case 'overdue':
                $qb->where('u.id IN (:ids)')
                    ->setParameter('ids', $this->overdueUserIdsSubquery($now));

                break;
            case 'onboarded':
                $qb->where('u.id IN (:ids)')
                    ->setParameter('ids', $this->onboardedUserIdsSubquery());

                break;
            case 'active':
                $qb->where('u.id IN (:ids)')
                    ->setParameter('ids', $this->contractRepository->findActiveContractUserIdsSubquery($now));

                break;
            case 'inactive':
                $qb->where('u.id NOT IN (:ids)')
                    ->setParameter('ids', $this->contractRepository->findActiveContractUserIdsSubquery($now));

                break;
        }

        $batch = 0;
        foreach ($qb->getQuery()->toIterable() as $user) {
            yield $user;
            if (++$batch >= 200) {
                $this->entityManager->clear();
                $batch = 0;
            }
        }
    }

    /**
     * @param Uuid[] $userIds
     *
     * @return string[] RFC-4122 strings of users with ≥1 admin-created order
     */
    public function findOnboardedUserIds(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        /** @var array<int, array{userId: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT IDENTITY(o.user) AS userId')
            ->from(Order::class, 'o')
            ->where('o.isAdminCreated = true')
            ->andWhere('o.user IN (:ids)')
            ->setParameter('ids', $userIds)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r): string => (string) $r['userId'], $rows);
    }

    /**
     * @return string[] sentinel zero-UUID when empty (DBAL forbids empty IN-lists)
     */
    private function onboardedUserIdsSubquery(): array
    {
        /** @var array<int, array{userId: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT IDENTITY(o.user) AS userId')
            ->from(Order::class, 'o')
            ->where('o.isAdminCreated = true')
            ->getQuery()
            ->getArrayResult();

        if ([] === $rows) {
            return ['00000000-0000-0000-0000-000000000000'];
        }

        return array_map(static fn (array $r): string => (string) $r['userId'], $rows);
    }

    /**
     * @return string[] RFC-4122 user UUID strings, with a sentinel zero-UUID
     *                  when there are no overdue users — empty arrays in
     *                  `IN (:overdueIds)` blow up at the DBAL layer
     */
    private function overdueUserIdsSubquery(\DateTimeImmutable $now): array
    {
        /** @var array<int, array{userId: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT IDENTITY(c.user) AS userId')
            ->from(Contract::class, 'c')
            ->where(
                '(c.terminatedAt IS NULL AND (c.failedBillingAttempts > 0 OR '
                .'(c.nextBillingDate IS NOT NULL AND c.nextBillingDate < :overdueThreshold))) OR '
                .'(c.outstandingDebtAmount IS NOT NULL AND c.outstandingDebtAmount > 0)'
            )
            ->setParameter('overdueThreshold', $now->modify('-1 day'))
            ->getQuery()
            ->getArrayResult();

        if ([] === $rows) {
            return ['00000000-0000-0000-0000-000000000000'];
        }

        return array_map(static fn (array $r): string => (string) $r['userId'], $rows);
    }

    /**
     * Generate next available self-billing prefix (P001, P002, ...).
     */
    public function getNextSelfBillingPrefix(): string
    {
        $connection = $this->entityManager->getConnection();

        $result = $connection->executeQuery(
            'SELECT self_billing_prefix FROM users
             WHERE self_billing_prefix IS NOT NULL
             ORDER BY self_billing_prefix DESC
             LIMIT 1'
        )->fetchOne();

        if (false === $result || null === $result) {
            return 'P001';
        }

        // Extract number from prefix (e.g., "P001" -> 1)
        $number = (int) substr($result, 1);

        return sprintf('P%03d', $number + 1);
    }
}
