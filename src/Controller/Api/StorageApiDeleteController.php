<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Command\DeleteStorageCommand;
use App\Repository\StorageRepository;
use App\Service\Security\StorageVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/places/{placeId}/storages/{storageId}', name: 'api_storages_delete', methods: ['DELETE'])]
#[IsGranted('ROLE_LANDLORD')]
final class StorageApiDeleteController extends AbstractController
{
    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(string $placeId, string $storageId): JsonResponse
    {
        $storage = $this->storageRepository->get(Uuid::fromString($storageId));
        $this->denyAccessUnlessGranted(StorageVoter::DELETE, $storage);

        // Verify storage belongs to the place
        if (!$storage->getPlace()->id->equals(Uuid::fromString($placeId))) {
            return new JsonResponse(['message' => 'Sklad nepatří k tomuto místu'], Response::HTTP_BAD_REQUEST);
        }

        // Check if storage has active orders/contracts
        if ($storage->isOccupied() || $storage->isReserved()) {
            return new JsonResponse(
                ['message' => 'Nelze smazat sklad s aktivní rezervací nebo objednávkou'],
                Response::HTTP_CONFLICT
            );
        }

        $command = new DeleteStorageCommand(storageId: $storage->id);
        $this->commandBus->dispatch($command);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
