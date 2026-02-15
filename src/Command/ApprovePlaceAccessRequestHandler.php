<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PlaceAccess;
use App\Repository\PlaceAccessRepository;
use App\Repository\PlaceAccessRequestRepository;
use App\Repository\UserRepository;
use App\Service\Identity\ProvideIdentity;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ApprovePlaceAccessRequestHandler
{
    public function __construct(
        private PlaceAccessRequestRepository $placeAccessRequestRepository,
        private PlaceAccessRepository $placeAccessRepository,
        private UserRepository $userRepository,
        private ProvideIdentity $identityProvider,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ApprovePlaceAccessRequestCommand $command): void
    {
        $request = $this->placeAccessRequestRepository->get($command->requestId);
        $admin = $this->userRepository->get($command->approvedById);
        $now = $this->clock->now();

        $request->approve($admin, $now);

        if (!$this->placeAccessRepository->hasAccess($request->requestedBy, $request->place)) {
            $placeAccess = new PlaceAccess(
                id: $this->identityProvider->next(),
                place: $request->place,
                user: $request->requestedBy,
                grantedAt: $now,
            );

            $this->placeAccessRepository->save($placeAccess);
        }
    }
}
