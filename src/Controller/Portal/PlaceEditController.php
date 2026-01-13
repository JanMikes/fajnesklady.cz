<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\UpdatePlaceCommand;
use App\Form\PlaceFormData;
use App\Form\PlaceFormType;
use App\Repository\PlaceRepository;
use App\Service\PlaceFileUploader;
use App\Service\Security\PlaceVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/places/{id}/edit', name: 'portal_places_edit')]
#[IsGranted('ROLE_LANDLORD')]
final class PlaceEditController extends AbstractController
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly PlaceFileUploader $fileUploader,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $place = $this->placeRepository->get(Uuid::fromString($id));

        // Check ownership via voter
        $this->denyAccessUnlessGranted(PlaceVoter::EDIT, $place);

        $formData = PlaceFormData::fromPlace($place);
        $form = $this->createForm(PlaceFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Handle map image upload
            $mapImagePath = null;
            if (null !== $formData->mapImage) {
                $this->fileUploader->deleteFile($place->mapImagePath);
                $mapImagePath = $this->fileUploader->uploadMapImage($formData->mapImage, $place->id);
            }

            $command = new UpdatePlaceCommand(
                placeId: $place->id,
                name: $formData->name,
                address: $formData->address,
                city: $formData->city,
                postalCode: $formData->postalCode,
                description: $formData->description,
                mapImagePath: $mapImagePath,
            );

            $this->commandBus->dispatch($command);

            $this->addFlash('success', 'Místo bylo úspěšně aktualizováno.');

            return $this->redirectToRoute('portal_places_list');
        }

        return $this->render('portal/place/edit.html.twig', [
            'form' => $form,
            'place' => $place,
        ]);
    }
}
