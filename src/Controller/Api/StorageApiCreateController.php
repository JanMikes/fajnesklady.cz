<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Command\CreateStorageCommand;
use App\Entity\Storage;
use App\Repository\PlaceRepository;
use App\Repository\StorageTypeRepository;
use App\Service\Security\PlaceVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/places/{placeId}/storages', name: 'api_storages_create', methods: ['POST'])]
#[IsGranted('ROLE_LANDLORD')]
final class StorageApiCreateController extends AbstractController
{
    use StorageApiValidationTrait;

    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly StorageTypeRepository $storageTypeRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request, string $placeId): JsonResponse
    {
        $place = $this->placeRepository->get(Uuid::fromString($placeId));
        $this->denyAccessUnlessGranted(PlaceVoter::EDIT, $place);

        $data = json_decode($request->getContent(), true);

        if (!$this->validateStorageData($data)) {
            return new JsonResponse(['message' => 'Neplatná data'], Response::HTTP_BAD_REQUEST);
        }

        $storageType = $this->storageTypeRepository->get(Uuid::fromString($data['storageTypeId']));

        // Verify storage type belongs to this place
        if (!$storageType->place->id->equals($place->id)) {
            return new JsonResponse(['message' => 'Typ skladu nepatří k tomuto místu'], Response::HTTP_BAD_REQUEST);
        }

        $command = new CreateStorageCommand(
            storageTypeId: $storageType->id,
            number: $data['number'],
            coordinates: $this->sanitizeCoordinates($data['coordinates']),
        );

        $envelope = $this->commandBus->dispatch($command);

        $handledStamp = $envelope->last(HandledStamp::class);
        if (null === $handledStamp) {
            return new JsonResponse(['message' => 'Nepodařilo se vytvořit sklad'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        /** @var Storage $storage */
        $storage = $handledStamp->getResult();

        return new JsonResponse([
            'id' => $storage->id->toRfc4122(),
            'number' => $storage->number,
            'storageTypeId' => $storage->storageType->id->toRfc4122(),
            'coordinates' => $storage->coordinates,
            'status' => $storage->status->value,
        ], Response::HTTP_CREATED);
    }
}
