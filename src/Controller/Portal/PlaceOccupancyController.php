<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Entity\User;
use App\Enum\StorageStatus;
use App\Query\GetPlaceTypeOccupancyOverview;
use App\Query\QueryBus;
use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
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
    '/portal/places/{placeId}/obsazenost',
    name: 'portal_places_occupancy',
    requirements: ['placeId' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'],
)]
#[IsGranted('ROLE_LANDLORD')]
final class PlaceOccupancyController extends AbstractController
{
    private const ALLOWED_FILTERS = ['all', 'available', 'occupied', 'blocked'];

    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly StorageRepository $storageRepository,
        private readonly StorageOccupancyService $occupancyService,
        private readonly QueryBus $queryBus,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(string $placeId, Request $request): Response
    {
        $place = $this->placeRepository->get(Uuid::fromString($placeId));
        $this->denyAccessUnlessGranted(PlaceVoter::VIEW, $place);

        /** @var User $user */
        $user = $this->getUser();
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $ownerScope = $isAdmin ? null : $user;
        $now = $this->clock->now();

        $placeOverview = $this->queryBus->handle(new GetPlaceTypeOccupancyOverview(
            placeId: $place->id,
            landlordId: $isAdmin ? null : $user->id,
        ));

        $storages = null === $ownerScope
            ? $this->storageRepository->findByPlace($place)
            : $this->storageRepository->findByOwnerAndPlace($user, $place);

        $views = $this->occupancyService->currentViews($storages, $now);

        $filter = $request->query->get('show', 'all');
        if (!in_array($filter, self::ALLOWED_FILTERS, true)) {
            $filter = 'all';
        }

        return $this->render('portal/place/occupancy.html.twig', [
            'place' => $place,
            'placeOverview' => $placeOverview,
            'storages' => $storages,
            'views' => $views,
            'isAdmin' => $isAdmin,
            'now' => $now,
            'filter' => $filter,
            'statusValues' => [
                'available' => StorageStatus::AVAILABLE->value,
                'occupied' => StorageStatus::OCCUPIED->value,
                'reserved' => StorageStatus::RESERVED->value,
                'blocked' => StorageStatus::MANUALLY_UNAVAILABLE->value,
            ],
        ]);
    }
}
