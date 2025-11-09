<?php

declare(strict_types=1);

namespace App\User\Repository;

use App\User\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements UserRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function save(User $user): void
    {
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function findById(Uuid $id): ?User
    {
        return $this->find($id);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => $email]);
    }

    public function findAll(): array
    {
        return $this->findBy([], ['createdAt' => 'DESC']);
    }

    public function findAllPaginated(int $page, int $limit): array
    {
        $offset = ($page - 1) * $limit;

        return $this->createQueryBuilder('u')
            ->orderBy('u.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countTotal(): int
    {
        $connection = $this->getEntityManager()->getConnection();
        $result = $connection->executeQuery('SELECT COUNT(id) FROM users')->fetchOne();

        return (int) $result;
    }

    public function countVerified(): int
    {
        $connection = $this->getEntityManager()->getConnection();
        $result = $connection->executeQuery(
            'SELECT COUNT(id) FROM users WHERE is_verified = :isVerified',
            ['isVerified' => true],
            ['isVerified' => \Doctrine\DBAL\Types\Types::BOOLEAN]
        )->fetchOne();

        return (int) $result;
    }

    public function countByRole(string $role): int
    {
        $connection = $this->getEntityManager()->getConnection();
        $result = $connection->executeQuery(
            'SELECT COUNT(id) FROM users WHERE roles::jsonb @> :role::jsonb',
            ['role' => json_encode([$role])],
            ['role' => \Doctrine\DBAL\Types\Types::STRING]
        )->fetchOne();

        return (int) $result;
    }
}
