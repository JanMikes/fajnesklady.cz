<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\AddStoragePhotoCommand;
use App\Command\UpdateStorageCommand;
use App\Form\StorageFormData;
use App\Form\StorageFormType;
use App\Repository\StorageRepository;
use App\Service\Security\StorageVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/storages/{id}/edit', name: 'portal_storages_edit')]
#[IsGranted('ROLE_LANDLORD')]
final class StorageEditController extends AbstractController
{
    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $storage = $this->storageRepository->get(Uuid::fromString($id));

        // Check ownership via voter
        $this->denyAccessUnlessGranted(StorageVoter::EDIT, $storage);

        $formData = StorageFormData::fromStorage($storage);
        $form = $this->createForm(StorageFormType::class, $formData, [
            'storage_type' => $storage->storageType,
            'is_edit' => true,
            'place' => $storage->place,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Convert CZK to halire for prices (only for non-uniform storage types)
            $pricePerWeek = null !== $formData->pricePerWeek
                ? (int) round($formData->pricePerWeek * 100)
                : null;
            $pricePerMonth = null !== $formData->pricePerMonth
                ? (int) round($formData->pricePerMonth * 100)
                : null;

            // Convert percentage to decimal for commission rate (only for admins)
            $commissionRate = null;
            $updateCommissionRate = false;
            if ($this->isGranted('ROLE_ADMIN')) {
                $commissionRate = null !== $formData->commissionRate
                    ? bcdiv((string) $formData->commissionRate, '100', 2)
                    : null;
                $updateCommissionRate = true;
            }

            $command = new UpdateStorageCommand(
                storageId: $storage->id,
                number: $formData->number,
                coordinates: $formData->getCoordinates(),
                storageTypeId: null !== $formData->storageTypeId ? Uuid::fromString($formData->storageTypeId) : null,
                pricePerWeek: $pricePerWeek,
                pricePerMonth: $pricePerMonth,
                updatePrices: !$storage->storageType->uniformStorages,
                commissionRate: $commissionRate,
                updateCommissionRate: $updateCommissionRate,
            );

            $this->commandBus->dispatch($command);

            foreach ($formData->photos as $photo) {
                $this->commandBus->dispatch(new AddStoragePhotoCommand(
                    storageId: $storage->id,
                    file: $photo,
                ));
            }

            $this->addFlash('success', 'Sklad byl úspěšně aktualizován.');

            return $this->redirectToRoute('portal_storages_list', [
                'placeId' => $storage->place->id->toRfc4122(),
                'storage_type' => $storage->storageType->id->toRfc4122(),
            ]);
        }

        return $this->render('portal/storage/edit.html.twig', [
            'form' => $form,
            'storage' => $storage,
        ]);
    }
}
