<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Command\UpdateStorageCommand;
use App\Repository\StorageRepository;
use App\Service\Security\StorageVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/places/{placeId}/storages/{storageId}', name: 'api_storages_update', methods: ['PUT'])]
#[IsGranted('ROLE_LANDLORD')]
final class StorageApiUpdateController extends AbstractController
{
    use StorageApiValidationTrait;

    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request, string $placeId, string $storageId): JsonResponse
    {
        $storage = $this->storageRepository->get(Uuid::fromString($storageId));
        $this->denyAccessUnlessGranted(StorageVoter::EDIT, $storage);

        // Verify storage belongs to the place
        if (!$storage->getPlace()->id->equals(Uuid::fromString($placeId))) {
            return new JsonResponse(['message' => 'Sklad nepatri k tomuto mistu'], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true);

        if (!$this->validateStorageData($data)) {
            return new JsonResponse(['message' => 'Neplatna data'], Response::HTTP_BAD_REQUEST);
        }

        $command = new UpdateStorageCommand(
            storageId: $storage->id,
            number: $data['number'],
            coordinates: $this->sanitizeCoordinates($data['coordinates']),
            storageTypeId: isset($data['storageTypeId']) ? Uuid::fromString($data['storageTypeId']) : null,
        );

        $this->commandBus->dispatch($command);

        return new JsonResponse([
            'id' => $storage->id->toRfc4122(),
            'number' => $data['number'],
            'storageTypeId' => $data['storageTypeId'],
            'coordinates' => $this->sanitizeCoordinates($data['coordinates']),
            'status' => $storage->status->value,
        ]);
    }
}
