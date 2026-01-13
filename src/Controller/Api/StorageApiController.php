<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Command\CreateStorageCommand;
use App\Command\DeleteStorageCommand;
use App\Command\UpdateStorageCommand;
use App\Entity\Storage;
use App\Entity\User;
use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageTypeRepository;
use App\Service\Security\PlaceVoter;
use App\Service\Security\StorageVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/places/{placeId}/storages')]
#[IsGranted('ROLE_LANDLORD')]
final class StorageApiController extends AbstractController
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly StorageRepository $storageRepository,
        private readonly StorageTypeRepository $storageTypeRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('', name: 'api_storages_create', methods: ['POST'])]
    public function create(Request $request, string $placeId): JsonResponse
    {
        $place = $this->placeRepository->get(Uuid::fromString($placeId));
        $this->denyAccessUnlessGranted(PlaceVoter::EDIT, $place);

        $data = json_decode($request->getContent(), true);

        if (!$this->validateStorageData($data)) {
            return new JsonResponse(['message' => 'Neplatna data'], Response::HTTP_BAD_REQUEST);
        }

        $storageType = $this->storageTypeRepository->get(Uuid::fromString($data['storageTypeId']));

        // Verify storage type belongs to this place
        if (!$storageType->place->id->equals($place->id)) {
            return new JsonResponse(['message' => 'Typ skladu nepatri k tomuto mistu'], Response::HTTP_BAD_REQUEST);
        }

        $command = new CreateStorageCommand(
            storageTypeId: $storageType->id,
            number: $data['number'],
            coordinates: $this->sanitizeCoordinates($data['coordinates']),
        );

        $envelope = $this->commandBus->dispatch($command);

        // Get the created storage from the envelope
        /** @var Storage $storage */
        $storage = $envelope->last(\Symfony\Component\Messenger\Stamp\HandledStamp::class)?->getResult();

        return new JsonResponse([
            'id' => $storage->id->toRfc4122(),
            'number' => $storage->number,
            'storageTypeId' => $storage->storageType->id->toRfc4122(),
            'coordinates' => $storage->coordinates,
            'status' => $storage->status->value,
        ], Response::HTTP_CREATED);
    }

    #[Route('/{storageId}', name: 'api_storages_update', methods: ['PUT'])]
    public function update(Request $request, string $placeId, string $storageId): JsonResponse
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

    #[Route('/{storageId}', name: 'api_storages_delete', methods: ['DELETE'])]
    public function delete(string $placeId, string $storageId): JsonResponse
    {
        $storage = $this->storageRepository->get(Uuid::fromString($storageId));
        $this->denyAccessUnlessGranted(StorageVoter::DELETE, $storage);

        // Verify storage belongs to the place
        if (!$storage->getPlace()->id->equals(Uuid::fromString($placeId))) {
            return new JsonResponse(['message' => 'Sklad nepatri k tomuto mistu'], Response::HTTP_BAD_REQUEST);
        }

        // Check if storage has active orders/contracts
        if ($storage->isOccupied() || $storage->isReserved()) {
            return new JsonResponse(
                ['message' => 'Nelze smazat sklad s aktivni rezervaci nebo objednavkou'],
                Response::HTTP_CONFLICT
            );
        }

        $command = new DeleteStorageCommand(storageId: $storage->id);
        $this->commandBus->dispatch($command);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function validateStorageData(?array $data): bool
    {
        if (null === $data) {
            return false;
        }

        if (empty($data['number']) || empty($data['storageTypeId']) || empty($data['coordinates'])) {
            return false;
        }

        $coords = $data['coordinates'];
        if (!isset($coords['x'], $coords['y'], $coords['width'], $coords['height'])) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $coordinates
     * @return array{x: int, y: int, width: int, height: int, rotation: int}
     */
    private function sanitizeCoordinates(array $coordinates): array
    {
        return [
            'x' => (int) ($coordinates['x'] ?? 0),
            'y' => (int) ($coordinates['y'] ?? 0),
            'width' => (int) ($coordinates['width'] ?? 100),
            'height' => (int) ($coordinates['height'] ?? 100),
            'rotation' => (int) ($coordinates['rotation'] ?? 0),
        ];
    }
}
