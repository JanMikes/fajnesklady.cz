<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use App\Service\Identity\ProvideIdentity;
use Doctrine\ORM\EntityManagerInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface;
use SymfonyCasts\Bundle\ResetPassword\Persistence\Repository\ResetPasswordRequestRepositoryTrait;
use SymfonyCasts\Bundle\ResetPassword\Persistence\ResetPasswordRequestRepositoryInterface;

final class ResetPasswordRequestRepository implements ResetPasswordRequestRepositoryInterface
{
    use ResetPasswordRequestRepositoryTrait;

    /**
     * Used by ResetPasswordRequestRepositoryTrait.
     *
     * @phpstan-ignore property.onlyWritten
     */
    private readonly EntityManagerInterface $entityManager;

    private readonly ProvideIdentity $identityProvider;

    public function __construct(EntityManagerInterface $entityManager, ProvideIdentity $identityProvider)
    {
        $this->entityManager = $entityManager;
        $this->identityProvider = $identityProvider;
    }

    public function createResetPasswordRequest(
        object $user,
        \DateTimeInterface $expiresAt,
        string $selector,
        string $hashedToken,
    ): ResetPasswordRequestInterface {
        \assert($user instanceof User);

        return new ResetPasswordRequest($this->identityProvider->next(), $user, $expiresAt, $selector, $hashedToken);
    }
}
