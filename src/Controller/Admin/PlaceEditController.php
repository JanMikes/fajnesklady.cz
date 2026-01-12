<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Command\UpdatePlaceCommand;
use App\Form\PlaceType;
use App\Repository\PlaceRepository;
use App\Security\PlaceVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/admin/places/{id}/edit', name: 'admin_places_edit')]
#[IsGranted('ROLE_LANDLORD')]
final class PlaceEditController extends AbstractController
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $place = $this->placeRepository->findById(Uuid::fromString($id));

        if (null === $place) {
            throw $this->createNotFoundException('Misto nenalezeno');
        }

        // Check ownership via voter
        $this->denyAccessUnlessGranted(PlaceVoter::EDIT, $place);

        $form = $this->createForm(PlaceType::class, [
            'name' => $place->name,
            'address' => $place->address,
            'description' => $place->description,
            'ownerId' => $place->owner->id->toRfc4122(),
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, mixed> $data */
            $data = $form->getData();

            $command = new UpdatePlaceCommand(
                placeId: $place->id,
                name: (string) $data['name'],
                address: (string) $data['address'],
                description: isset($data['description']) ? (string) $data['description'] : null,
            );

            $this->commandBus->dispatch($command);

            $this->addFlash('success', 'Misto bylo uspesne aktualizovano.');

            return $this->redirectToRoute('admin_places_list');
        }

        return $this->render('admin/place/edit.html.twig', [
            'form' => $form,
            'place' => $place,
        ]);
    }
}
