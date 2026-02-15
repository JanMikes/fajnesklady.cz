<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\PlaceAccessRequestRepository;
use App\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class DenyPlaceAccessRequestHandler
{
    public function __construct(
        private PlaceAccessRequestRepository $placeAccessRequestRepository,
        private UserRepository $userRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(DenyPlaceAccessRequestCommand $command): void
    {
        $request = $this->placeAccessRequestRepository->get($command->requestId);
        $admin = $this->userRepository->get($command->deniedById);

        $request->deny($admin, $this->clock->now());
    }
}
