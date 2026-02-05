<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use App\Service\Identity\ProvideIdentity;
use Doctrine\ORM\EntityManagerInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface;
use SymfonyCasts\Bundle\ResetPassword\Persistence\ResetPasswordRequestRepositoryInterface;

class ResetPasswordRequestRepository implements ResetPasswordRequestRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProvideIdentity $identityProvider,
    ) {
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

    public function getUserIdentifier(object $user): string
    {
        return (string) $this->entityManager
            ->getUnitOfWork()
            ->getSingleIdentifierValue($user);
    }

    public function persistResetPasswordRequest(ResetPasswordRequestInterface $resetPasswordRequest): void
    {
        $this->entityManager->persist($resetPasswordRequest);
        $this->entityManager->flush();
    }

    public function findResetPasswordRequest(string $selector): ?ResetPasswordRequestInterface
    {
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(ResetPasswordRequest::class, 'r')
            ->where('r.selector = :selector')
            ->setParameter('selector', $selector)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function getMostRecentNonExpiredRequestDate(object $user): ?\DateTimeInterface
    {
        /** @var ResetPasswordRequestInterface|null $resetPasswordRequest */
        $resetPasswordRequest = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(ResetPasswordRequest::class, 'r')
            ->where('r.user = :user')
            ->setParameter('user', $user)
            ->orderBy('r.requestedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (null !== $resetPasswordRequest && !$resetPasswordRequest->isExpired()) {
            return $resetPasswordRequest->getRequestedAt();
        }

        return null;
    }

    public function removeResetPasswordRequest(ResetPasswordRequestInterface $resetPasswordRequest): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(ResetPasswordRequest::class, 'r')
            ->where('r.user = :user')
            ->setParameter('user', $resetPasswordRequest->getUser())
            ->getQuery()
            ->execute();
    }

    public function removeExpiredResetPasswordRequests(): int
    {
        $time = new \DateTimeImmutable('-1 week');

        return (int) $this->entityManager->createQueryBuilder()
            ->delete(ResetPasswordRequest::class, 'r')
            ->where('r.expiresAt <= :time')
            ->setParameter('time', $time)
            ->getQuery()
            ->execute();
    }

    public function removeRequests(object $user): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(ResetPasswordRequest::class, 'r')
            ->where('r.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
