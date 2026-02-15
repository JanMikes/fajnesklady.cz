<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PlaceAccessRequest;
use App\Repository\PlaceAccessRepository;
use App\Repository\PlaceAccessRequestRepository;
use App\Repository\PlaceRepository;
use App\Repository\UserRepository;
use App\Service\Identity\ProvideIdentity;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RequestPlaceAccessHandler
{
    public function __construct(
        private PlaceRepository $placeRepository,
        private UserRepository $userRepository,
        private PlaceAccessRepository $placeAccessRepository,
        private PlaceAccessRequestRepository $placeAccessRequestRepository,
        private ProvideIdentity $identityProvider,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RequestPlaceAccessCommand $command): PlaceAccessRequest
    {
        $place = $this->placeRepository->get($command->placeId);
        $user = $this->userRepository->get($command->requestedById);

        if ($this->placeAccessRepository->hasAccess($user, $place)) {
            throw new \DomainException('Již máte přístup k tomuto místu.');
        }

        $existing = $this->placeAccessRequestRepository->findPendingByUserAndPlace($user, $place);
        if (null !== $existing) {
            throw new \DomainException('Žádost o přístup k tomuto místu již byla odeslána.');
        }

        $request = new PlaceAccessRequest(
            id: $this->identityProvider->next(),
            place: $place,
            requestedBy: $user,
            message: $command->message,
            createdAt: $this->clock->now(),
        );

        $this->placeAccessRequestRepository->save($request);

        return $request;
    }
}
