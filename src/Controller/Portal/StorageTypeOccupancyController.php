<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Entity\User;
use App\Query\GetStorageTypeOccupancy;
use App\Query\QueryBus;
use App\Repository\PlaceRepository;
use App\Repository\StorageTypeRepository;
use App\Service\Security\PlaceVoter;
use App\Service\Storage\StorageOccupancyService;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route(
    '/portal/places/{placeId}/storage-types/{id}/obsazenost',
    name: 'portal_storage_type_occupancy',
    requirements: [
        'placeId' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}',
        'id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}',
    ],
)]
#[IsGranted('ROLE_LANDLORD')]
final class StorageTypeOccupancyController extends AbstractController
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly StorageTypeRepository $storageTypeRepository,
        private readonly StorageOccupancyService $occupancyService,
        private readonly QueryBus $queryBus,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request, string $placeId, string $id): Response
    {
        $place = $this->placeRepository->get(Uuid::fromString($placeId));
        $this->denyAccessUnlessGranted(PlaceVoter::VIEW, $place);

        $storageType = $this->storageTypeRepository->get(Uuid::fromString($id));
        if (!$storageType->place->id->equals($place->id)) {
            throw $this->createNotFoundException();
        }

        /** @var User $user */
        $user = $this->getUser();
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $now = $this->clock->now();

        $result = $this->queryBus->handle(new GetStorageTypeOccupancy(
            placeId: $place->id,
            storageTypeId: $storageType->id,
            landlordId: $isAdmin ? null : $user->id,
        ));

        $rangeFrom = $now->setTime(0, 0);
        $rangeTo = $rangeFrom->modify('+90 days');
        $storages = array_map(static fn ($row) => $row->storage, $result->rows);
        $spans = $this->occupancyService->spansInRange($storages, $rangeFrom, $rangeTo);

        $show = (string) $request->query->get('show', 'all');
        if (!in_array($show, ['all', 'occupied', 'free', 'blocked'], true)) {
            $show = 'all';
        }

        return $this->render('portal/storage_type/occupancy.html.twig', [
            'place' => $place,
            'storageType' => $storageType,
            'result' => $result,
            'spans' => $spans,
            'rangeFrom' => $rangeFrom,
            'rangeTo' => $rangeTo,
            'show' => $show,
            'isAdmin' => $isAdmin,
        ]);
    }
}
