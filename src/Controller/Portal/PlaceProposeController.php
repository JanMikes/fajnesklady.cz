<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\CreatePlaceCommand;
use App\Command\RequestPlaceAccessCommand;
use App\Entity\Place;
use App\Entity\User;
use App\Enum\PlaceType;
use App\Event\PlaceProposed;
use App\Form\PlaceProposeFormData;
use App\Form\PlaceProposeFormType;
use App\Service\Identity\ProvideIdentity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/places/propose', name: 'portal_places_propose')]
#[IsGranted('ROLE_LANDLORD')]
final class PlaceProposeController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly MessageBusInterface $eventBus,
        private readonly ProvideIdentity $identityProvider,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $form = $this->createForm(PlaceProposeFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var PlaceProposeFormData $formData */
            $formData = $form->getData();

            /** @var User $user */
            $user = $this->getUser();

            $placeId = $this->identityProvider->next();

            $command = new CreatePlaceCommand(
                placeId: $placeId,
                name: $formData->name,
                address: $formData->address,
                city: $formData->city,
                postalCode: $formData->postalCode,
                description: $formData->description,
                type: PlaceType::SAMOSTATNY_SKLAD,
            );

            $envelope = $this->commandBus->dispatch($command);
            /** @var Place $place */
            $place = $envelope->last(HandledStamp::class)?->getResult();

            // Automatically request access for the proposing landlord
            $this->commandBus->dispatch(new RequestPlaceAccessCommand(
                placeId: $place->id,
                requestedById: $user->id,
            ));

            // Notify admin
            $this->eventBus->dispatch(new PlaceProposed(
                placeId: $place->id,
                proposedById: $user->id,
                occurredOn: new \DateTimeImmutable(),
            ));

            $this->addFlash('success', 'Návrh nového místa byl odeslán. O přidělení přístupu budete informováni e-mailem.');

            return $this->redirectToRoute('portal_places_list');
        }

        return $this->render('portal/place/propose.html.twig', [
            'form' => $form,
        ]);
    }
}
