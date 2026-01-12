<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\DeletePlaceCommand;
use App\Repository\PlaceRepository;
use App\Service\Security\PlaceVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/places/{id}/delete', name: 'portal_places_delete', methods: ['POST'])]
#[IsGranted('ROLE_LANDLORD')]
final class PlaceDeleteController extends AbstractController
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $place = $this->placeRepository->get(Uuid::fromString($id));

        $this->denyAccessUnlessGranted(PlaceVoter::DELETE, $place);

        // CSRF protection
        if (!$this->isCsrfTokenValid('delete_place_'.$id, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Neplatny CSRF token.');

            return $this->redirectToRoute('portal_places_list');
        }

        $this->commandBus->dispatch(new DeletePlaceCommand(placeId: $place->id));

        $this->addFlash('success', 'Misto bylo uspesne smazano.');

        return $this->redirectToRoute('portal_places_list');
    }
}
