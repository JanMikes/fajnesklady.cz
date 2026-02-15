<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\RequestPlaceAccessCommand;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/places/{placeId}/request-access', name: 'portal_place_request_access', methods: ['POST'])]
#[IsGranted('ROLE_LANDLORD')]
final class PlaceAccessRequestController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(string $placeId): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        try {
            $this->commandBus->dispatch(new RequestPlaceAccessCommand(
                placeId: Uuid::fromString($placeId),
                requestedById: $user->id,
            ));

            $this->addFlash('success', 'Žádost o přístup byla odeslána.');
        } catch (\DomainException $e) {
            $this->addFlash('warning', $e->getMessage());
        }

        return $this->redirectToRoute('portal_places_list');
    }
}
