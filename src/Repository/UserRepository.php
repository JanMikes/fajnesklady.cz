<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Enum\UserRole;
use App\Exception\UserNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class UserRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
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
}
