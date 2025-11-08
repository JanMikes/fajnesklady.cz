<?php

declare(strict_types=1);

namespace App\User\Repository;

use App\User\Entity\User;
use Symfony\Component\Uid\Uuid;

interface UserRepositoryInterface
{
    public function save(User $user): void;

    public function findById(Uuid $id): ?User;

    public function findByEmail(string $email): ?User;

    /**
     * @return User[]
     */
    public function findAll(): array;

    /**
     * @return User[]
     */
    public function findAllPaginated(int $page, int $limit): array;
}
