<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\UpdatePlaceStorageCodeConfigCommand;
use App\Form\PlaceStorageCodeConfigFormData;
use App\Form\PlaceStorageCodeConfigFormType;
use App\Repository\PlaceRepository;
use App\Repository\PlaceStorageCodeUsageRepository;
use App\Repository\StorageRepository;
use App\Service\Security\PlaceVoter;
use App\Service\StorageCodeGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/places/{placeId}/access-codes', name: 'portal_place_access_codes')]
#[IsGranted('ROLE_LANDLORD')]
final class PlaceAccessCodesController extends AbstractController
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly StorageRepository $storageRepository,
        private readonly PlaceStorageCodeUsageRepository $usageRepository,
        private readonly StorageCodeGenerator $codeGenerator,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(string $placeId, Request $request): Response
    {
        $place = $this->placeRepository->get(Uuid::fromString($placeId));
        $this->denyAccessUnlessGranted(PlaceVoter::EDIT, $place);

        $formData = PlaceStorageCodeConfigFormData::fromPlace($place);
        $form = $this->createForm(PlaceStorageCodeConfigFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->commandBus->dispatch(new UpdatePlaceStorageCodeConfigCommand(
                placeId: $place->id,
                enabled: $formData->enabled,
                digits: $formData->digits,
                from: $formData->from,
                to: $formData->to,
            ));

            $this->addFlash('success', 'Nastavení přístupových kódů bylo uloženo.');

            return $this->redirectToRoute('portal_place_access_codes', ['placeId' => $place->id->toRfc4122()]);
        }

        $storages = $this->storageRepository->findByPlace($place);
        $usedCodesCount = count($this->usageRepository->findCodesForPlace($place));
        $availableCount = $place->storageCodesEnabled ? $this->codeGenerator->availableCount($place) : null;
        $emptyCount = 0;
        foreach ($storages as $storage) {
            if (null === $storage->lockCode) {
                ++$emptyCount;
            }
        }

        return $this->render('portal/place/access_codes.html.twig', [
            'place' => $place,
            'form' => $form,
            'storages' => $storages,
            'usedCodesCount' => $usedCodesCount,
            'availableCount' => $availableCount,
            'emptyCount' => $emptyCount,
        ]);
    }
}
