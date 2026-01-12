<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

#[AsMessageHandler]
final readonly class VerifyEmailHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private VerifyEmailHelperInterface $verifyEmailHelper,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(VerifyEmailCommand $command): void
    {
        $user = $this->userRepository->get($command->userId);

        $this->verifyEmailHelper->validateEmailConfirmation(
            signedUrl: $command->signedUrl,
            userId: (string) $user->id,
            userEmail: $user->email,
        );

        $user->markAsVerified($this->clock->now());

        $this->userRepository->save($user);
    }
}
