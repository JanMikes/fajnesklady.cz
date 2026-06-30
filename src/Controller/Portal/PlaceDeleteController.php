<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\DeletePlaceCommand;
use App\Entity\User;
use App\Repository\PlaceRepository;
use App\Service\Security\PasswordConfirmation;
use App\Service\Security\PlaceVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/places/{id}/delete', name: 'portal_places_delete', methods: ['POST'])]
#[IsGranted('ROLE_LANDLORD')]
final class PlaceDeleteController extends AbstractController
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly PasswordConfirmation $passwordConfirmation,
    ) {
    }

    public function __invoke(Request $request, string $id, #[CurrentUser] User $user): Response
    {
        $place = $this->placeRepository->get(Uuid::fromString($id));

        $this->denyAccessUnlessGranted(PlaceVoter::DELETE, $place);

        if (!$this->passwordConfirmation->isValid($user, $request->request->getString('password'))) {
            $this->addFlash('error', 'Zadané heslo není správné. Akce nebyla provedena.');

            return $this->redirectToRoute('portal_places_detail', ['id' => $id]);
        }

        $this->commandBus->dispatch(new DeletePlaceCommand(placeId: $place->id));

        $this->addFlash('success', 'Místo bylo úspěšně smazáno.');

        return $this->redirectToRoute('portal_places_list');
    }
}
