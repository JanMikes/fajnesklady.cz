<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Command\ReorderStorageTypesCommand;
use App\Repository\PlaceRepository;
use App\Service\Messenger\HandlerFailureUnwrap;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/places/{placeId}/storage-types/reorder', name: 'portal_storage_types_reorder', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class StorageTypeReorderController extends AbstractController
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request, string $placeId): JsonResponse
    {
        $place = $this->placeRepository->get(Uuid::fromString($placeId));

        $payload = json_decode($request->getContent(), true);
        $ids = is_array($payload) ? ($payload['ids'] ?? null) : null;

        if (!is_array($ids) || [] === $ids) {
            return new JsonResponse(['error' => 'Chybí seznam identifikátorů typů skladů.'], 400);
        }

        $storageTypeIds = [];
        foreach ($ids as $id) {
            if (!is_string($id) || !Uuid::isValid($id)) {
                return new JsonResponse(['error' => 'Neplatný identifikátor typu skladu.'], 400);
            }

            $storageTypeIds[] = Uuid::fromString($id);
        }

        try {
            $this->commandBus->dispatch(new ReorderStorageTypesCommand(
                placeId: $place->id,
                orderedStorageTypeIds: $storageTypeIds,
            ));
        } catch (\Throwable $rawException) {
            // Re-throw the original handler exception so #[WithHttpStatus]
            // (e.g. StorageTypeNotFound → 404) applies instead of a generic 500
            throw HandlerFailureUnwrap::unwrap($rawException);
        }

        return new JsonResponse(['success' => true]);
    }
}
