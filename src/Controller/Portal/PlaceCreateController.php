<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\CreatePlaceCommand;
use App\Entity\User;
use App\Form\PlaceFormData;
use App\Form\PlaceFormType;
use App\Service\Identity\ProvideIdentity;
use App\Service\PlaceFileUploader;
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
        private readonly ProvideIdentity $identityProvider,
        private readonly PlaceFileUploader $fileUploader,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $form = $this->createForm(PlaceFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var PlaceFormData $formData */
            $formData = $form->getData();

            // Generate place ID upfront for file uploads
            $placeId = $this->identityProvider->next();

            // If not admin, owner is always current user
            $ownerId = $this->isGranted('ROLE_ADMIN') && null !== $formData->ownerId
                ? Uuid::fromString($formData->ownerId)
                : $user->id;

            // Handle map image upload
            $mapImagePath = null;
            if (null !== $formData->mapImage) {
                $mapImagePath = $this->fileUploader->uploadMapImage($formData->mapImage, $placeId);
            }

            $command = new CreatePlaceCommand(
                placeId: $placeId,
                name: $formData->name,
                address: $formData->address,
                city: $formData->city,
                postalCode: $formData->postalCode,
                description: $formData->description,
                ownerId: $ownerId,
                mapImagePath: $mapImagePath,
            );

            $this->commandBus->dispatch($command);

            $this->addFlash('success', 'Místo bylo úspěšně vytvořeno.');

            return $this->redirectToRoute('portal_places_list');
        }

        return $this->render('portal/place/create.html.twig', [
            'form' => $form,
        ]);
    }
}
