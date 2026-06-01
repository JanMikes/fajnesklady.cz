<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\User;
use App\Enum\UserRole;
use App\Exception\UserNotFound;
use App\Value\UserListCriteria;
use App\Value\UserListRow;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class UserRepository
{
    /**
     * Mirrors {@see ContractRepository::findOverdueUserIds()} as a correlated
     * EXISTS over the listed user. Kept in sync with that predicate manually.
     */
    private const string OVERDUE_EXISTS = <<<'SQL'
        EXISTS (
            SELECT 1 FROM contract oc
            WHERE oc.user_id = u.id
              AND (
                (oc.terminated_at IS NULL AND (oc.failed_billing_attempts > 0 OR
                    (oc.next_billing_date IS NOT NULL AND oc.next_billing_date < :overdueThreshold)))
                OR (oc.outstanding_debt_amount IS NOT NULL AND oc.outstanding_debt_amount > 0)
              )
        )
        SQL;

    private const string ONBOARDED_EXISTS = <<<'SQL'
        EXISTS (
            SELECT 1 FROM orders oo
            WHERE oo.user_id = u.id AND oo.is_admin_created = true
        )
        SQL;

    /**
     * Correlated EXISTS for "user has ≥1 active (non-terminated, not-yet-expired)
     * contract" — mirrors {@see ContractRepository::findActiveContractUserIdsSubquery()}.
     */
    private const string ACTIVE_CONTRACT_EXISTS = <<<'SQL'
        EXISTS (
            SELECT 1 FROM contract ac
            WHERE ac.user_id = u.id
              AND ac.terminated_at IS NULL
              AND (ac.end_date IS NULL OR ac.end_date >= :now)
        )
        SQL;

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
     * One enriched SQL query backing the admin user list (spec 066). Returns
     * rows already carrying the derived columns (active/total contract counts,
     * MRR, YRR, overdue, onboarded) so search / filter / sort / pagination all
     * operate over the full enriched dataset instead of per-page enrichment.
     *
     * The MRR / YRR / active / total aggregate sub-select reuses the exact
     * predicates of {@see ContractRepository::loadCustomerStatsByUserIds()}.
     * The overdue EXISTS mirrors {@see ContractRepository::findOverdueUserIds()}
     * (no free-contract filter there, matching the badge it currently powers).
     *
     * @return list<UserListRow>
     */
    public function findForAdminList(UserListCriteria $criteria, \DateTimeImmutable $now): array
    {
        [$where, $params, $types] = $this->buildAdminListPredicate($criteria, $now);

        // Sort column comes from the whitelist in UserListCriteria — never raw
        // user input — so interpolating it is safe (DBAL can't bind identifiers).
        $orderBy = sprintf('%s %s, u.id ASC', $criteria->sortExpression(), 'asc' === $criteria->sortDirection ? 'ASC' : 'DESC');

        $params['limit'] = $criteria->limit;
        $params['offset'] = ($criteria->page - 1) * $criteria->limit;

        $sql = sprintf(
            <<<'SQL'
                SELECT
                    u.id::text AS id,
                    u.first_name,
                    u.last_name,
                    u.email,
                    u.phone,
                    u.roles,
                    u.is_verified,
                    u.deactivated_at,
                    u.created_at,
                    COALESCE(agg.active_count, 0) AS active_count,
                    COALESCE(agg.total_count, 0) AS total_count,
                    COALESCE(agg.mrr, 0) AS mrr,
                    COALESCE(agg.yrr, 0) AS yrr,
                    %s AS is_overdue,
                    %s AS is_onboarded
                FROM users u
                LEFT JOIN (%s) agg ON agg.user_id = u.id
                WHERE %s
                ORDER BY %s
                LIMIT :limit OFFSET :offset
                SQL,
            self::OVERDUE_EXISTS,
            self::ONBOARDED_EXISTS,
            $this->aggregateSubSelect(),
            $where,
            $orderBy,
        );

        $rows = $this->entityManager->getConnection()->executeQuery($sql, $params, $types)->fetchAllAssociative();

        return array_map(static function (array $row): UserListRow {
            /** @var array<string> $roles */
            $roles = json_decode((string) $row['roles'], true, 512, \JSON_THROW_ON_ERROR);

            return new UserListRow(
                id: Uuid::fromString((string) $row['id']),
                fullName: trim(sprintf('%s %s', (string) $row['first_name'], (string) $row['last_name'])),
                email: (string) $row['email'],
                phone: null !== $row['phone'] ? (string) $row['phone'] : null,
                roles: $roles,
                isVerified: (bool) $row['is_verified'],
                isDeactivated: null !== $row['deactivated_at'],
                createdAt: new \DateTimeImmutable((string) $row['created_at']),
                activeCount: (int) $row['active_count'],
                totalCount: (int) $row['total_count'],
                mrrInHaler: (int) $row['mrr'],
                yrrInHaler: (int) $row['yrr'],
                isOverdue: (bool) $row['is_overdue'],
                isOnboarded: (bool) $row['is_onboarded'],
            );
        }, $rows);
    }

    public function countForAdminList(UserListCriteria $criteria, \DateTimeImmutable $now): int
    {
        [$where, $params, $types] = $this->buildAdminListPredicate($criteria, $now);

        $sql = sprintf(
            <<<'SQL'
                SELECT COUNT(*)
                FROM users u
                LEFT JOIN (%s) agg ON agg.user_id = u.id
                WHERE %s
                SQL,
            $this->aggregateSubSelect(),
            $where,
        );

        return (int) $this->entityManager->getConnection()->executeQuery($sql, $params, $types)->fetchOne();
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
            ['isVerified' => Types::BOOLEAN]
        )->fetchOne();

        return (int) $result;
    }

    public function countByRole(string $role): int
    {
        $connection = $this->entityManager->getConnection();
        $result = $connection->executeQuery(
            'SELECT COUNT(id) FROM users WHERE roles::jsonb @> :role::jsonb',
            ['role' => json_encode([$role])],
            ['role' => Types::STRING]
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
            ['role' => Types::STRING]
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
                'landlordRole' => Types::STRING,
                'adminRole' => Types::STRING,
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
     * @param string[]|null $activeUserIds RFC-4122 user UUID strings (sentinel-padded if empty),
     *                                     as returned by ContractRepository::findActiveContractUserIdsSubquery().
     *                                     Pass it in to avoid re-running that subquery when the
     *                                     caller already needs it for related counts.
     *
     * @return User[]
     */
    public function findWithActiveContractsPaginated(int $page, int $limit, \DateTimeImmutable $now, ?array $activeUserIds = null): array
    {
        $offset = ($page - 1) * $limit;
        $activeUserIds ??= $this->contractRepository->findActiveContractUserIdsSubquery($now);

        return $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.id IN (:activeIds)')
            ->setParameter('activeIds', $activeUserIds)
            ->orderBy('u.createdAt', 'DESC')
            ->addOrderBy('u.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param string[]|null $activeUserIds see {@see self::findWithActiveContractsPaginated()}
     */
    public function countWithActiveContracts(\DateTimeImmutable $now, ?array $activeUserIds = null): int
    {
        $activeUserIds ??= $this->contractRepository->findActiveContractUserIdsSubquery($now);

        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(User::class, 'u')
            ->where('u.id IN (:activeIds)')
            ->setParameter('activeIds', $activeUserIds)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param string[]|null $activeUserIds see {@see self::findWithActiveContractsPaginated()}
     *
     * @return User[]
     */
    public function findWithoutActiveContractsPaginated(int $page, int $limit, \DateTimeImmutable $now, ?array $activeUserIds = null): array
    {
        $offset = ($page - 1) * $limit;
        $activeUserIds ??= $this->contractRepository->findActiveContractUserIdsSubquery($now);

        return $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.id NOT IN (:activeIds)')
            ->setParameter('activeIds', $activeUserIds)
            ->orderBy('u.createdAt', 'DESC')
            ->addOrderBy('u.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param string[]|null $activeUserIds see {@see self::findWithActiveContractsPaginated()}
     */
    public function countWithoutActiveContracts(\DateTimeImmutable $now, ?array $activeUserIds = null): int
    {
        $activeUserIds ??= $this->contractRepository->findActiveContractUserIdsSubquery($now);

        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(User::class, 'u')
            ->where('u.id NOT IN (:activeIds)')
            ->setParameter('activeIds', $activeUserIds)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUnverified(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(User::class, 'u')
            ->where('u.isVerified = false')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return User[]
     */
    public function findUnverifiedPaginated(int $page, int $limit): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.isVerified = false')
            ->orderBy('u.createdAt', 'DESC')
            ->addOrderBy('u.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Streamed iteration for export. Mirrors {@see self::findAllPaginated()},
     * {@see self::findOverduePaginated()}, {@see self::findOnboardedPaginated()},
     * {@see self::findWithActiveContractsPaginated()}, and
     * {@see self::findWithoutActiveContractsPaginated()} but without pagination.
     *
     * @return iterable<User>
     */
    public function streamForExport(?string $filter, ?string $search, \DateTimeImmutable $now): iterable
    {
        $qb = $this->buildExportQueryBuilder($filter, $search, $now);

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
     * UUID list for the same query as {@see self::streamForExport()}, used by
     * the export controller to pre-compute debtor / onboarded membership sets
     * once per request without paying a per-row query.
     *
     * @return Uuid[]
     */
    public function findIdsForExport(?string $filter, ?string $search, \DateTimeImmutable $now): array
    {
        /** @var array<int, array{id: Uuid}> $rows */
        $rows = $this->buildExportQueryBuilder($filter, $search, $now)
            ->select('u.id')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r): Uuid => $r['id'], $rows);
    }

    private function buildExportQueryBuilder(?string $filter, ?string $search, \DateTimeImmutable $now): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->orderBy('u.createdAt', 'DESC')
            ->addOrderBy('u.id', 'DESC');

        $trimmedSearch = null !== $search ? trim($search) : '';
        if ('' !== $trimmedSearch) {
            $qb->andWhere(
                "LOWER(CONCAT(u.firstName, ' ', u.lastName)) LIKE :search "
                .'OR LOWER(u.email) LIKE :search '
                .'OR LOWER(u.phone) LIKE :search'
            )->setParameter('search', '%'.mb_strtolower($trimmedSearch).'%');
        }

        switch ($filter) {
            case 'overdue':
                $qb->andWhere('u.id IN (:ids)')
                    ->setParameter('ids', $this->overdueUserIdsSubquery($now));

                break;
            case 'onboarded':
                $qb->andWhere('u.id IN (:ids)')
                    ->setParameter('ids', $this->onboardedUserIdsSubquery());

                break;
            case 'active':
                $qb->andWhere('u.id IN (:ids)')
                    ->setParameter('ids', $this->contractRepository->findActiveContractUserIdsSubquery($now));

                break;
            case 'inactive':
                $qb->andWhere('u.id NOT IN (:ids)')
                    ->setParameter('ids', $this->contractRepository->findActiveContractUserIdsSubquery($now));

                break;
            case 'unverified':
                $qb->andWhere('u.isVerified = false');

                break;
        }

        return $qb;
    }

    /**
     * Shared FROM/JOIN WHERE clause + bound params for the admin-list query and
     * its matching count. Returns [whereSql, params, types].
     *
     * @return array{string, array<string, mixed>, array<string, mixed>}
     */
    private function buildAdminListPredicate(UserListCriteria $criteria, \DateTimeImmutable $now): array
    {
        $params = [
            'now' => $now,
            'overdueThreshold' => $now->modify('-1 day'),
        ];
        $types = [
            'now' => Types::DATETIME_IMMUTABLE,
            'overdueThreshold' => Types::DATETIME_IMMUTABLE,
        ];

        $conditions = [];

        if (null !== $criteria->search) {
            $conditions[] = '((u.first_name || \' \' || u.last_name) ILIKE :search OR u.email ILIKE :search OR u.phone ILIKE :search)';
            $params['search'] = '%'.$criteria->search.'%';
            $types['search'] = Types::STRING;
        }

        $filterClause = match ($criteria->filter) {
            'overdue' => self::OVERDUE_EXISTS,
            'onboarded' => self::ONBOARDED_EXISTS,
            'active' => self::ACTIVE_CONTRACT_EXISTS,
            'inactive' => 'NOT '.self::ACTIVE_CONTRACT_EXISTS,
            'unverified' => 'u.is_verified = false',
            default => null,
        };
        if (null !== $filterClause) {
            $conditions[] = $filterClause;
        }

        $where = [] === $conditions ? 'TRUE' : implode(' AND ', $conditions);

        return [$where, $params, $types];
    }

    private function aggregateSubSelect(): string
    {
        return <<<'SQL'
            SELECT c.user_id,
                   COUNT(*) AS total_count,
                   COUNT(*) FILTER (
                       WHERE c.terminated_at IS NULL
                         AND (c.end_date IS NULL OR c.end_date >= :now)
                   ) AS active_count,
                   COALESCE(SUM(COALESCE(c.individual_monthly_amount, o.total_price)) FILTER (
                       WHERE c.terminated_at IS NULL
                         AND (c.end_date IS NULL OR c.end_date >= :now)
                         AND (c.end_date IS NULL OR (c.end_date - c.start_date) >= 28)
                         AND c.payment_frequency != 'yearly'
                   ), 0) AS mrr,
                   COALESCE(SUM(o.total_price) FILTER (
                       WHERE c.terminated_at IS NULL
                         AND (c.end_date IS NULL OR c.end_date >= :now)
                         AND c.payment_frequency = 'yearly'
                   ), 0) AS yrr
            FROM contract c
            INNER JOIN orders o ON o.id = c.order_id
            GROUP BY c.user_id
            SQL;
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
