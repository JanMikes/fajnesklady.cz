<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\CreatePlaceCommand;
use App\Entity\User;
use App\Form\PlaceType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/places/create', name: 'portal_places_create')]
#[IsGranted('ROLE_LANDLORD')]
final class PlaceCreateController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $form = $this->createForm(PlaceType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, mixed> $data */
            $data = $form->getData();

            // If not admin, owner is always current user
            $ownerId = $this->isGranted('ROLE_ADMIN') && isset($data['ownerId'])
                ? Uuid::fromString((string) $data['ownerId'])
                : $user->id;

            $command = new CreatePlaceCommand(
                name: (string) $data['name'],
                address: (string) $data['address'],
                description: isset($data['description']) ? (string) $data['description'] : null,
                ownerId: $ownerId,
            );

            $this->commandBus->dispatch($command);

            $this->addFlash('success', 'Misto bylo uspesne vytvoreno.');

            return $this->redirectToRoute('portal_places_list');
        }

        return $this->render('portal/place/create.html.twig', [
            'form' => $form,
        ]);
    }
}
