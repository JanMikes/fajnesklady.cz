<?php

declare(strict_types=1);

namespace App\Command;

use App\Event\EmailVerified;
use App\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

#[AsMessageHandler]
final readonly class VerifyEmailHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private VerifyEmailHelperInterface $verifyEmailHelper,
        private MessageBusInterface $eventBus,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(VerifyEmailCommand $command): void
    {
        // Find user
        $user = $this->userRepository->findById($command->userId);
        if (null === $user) {
            throw new \DomainException('User not found');
        }

        // Validate token using the legacy method that accepts signed URL
        $this->verifyEmailHelper->validateEmailConfirmation(
            signedUrl: $command->signedUrl,
            userId: (string) $user->getId(),
            userEmail: $user->getEmail(),
        );

        $now = $this->clock->now();

        // Mark user as verified
        $user->markAsVerified($now);

        // Save user
        $this->userRepository->save($user);

        // Dispatch EmailVerified event
        $this->eventBus->dispatch(new EmailVerified(
            userId: $user->getId(),
            occurredOn: $now,
        ));
    }
}
