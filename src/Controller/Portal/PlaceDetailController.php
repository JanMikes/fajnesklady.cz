<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Entity\User;
use App\Query\GetPlaceDashboardStats;
use App\Query\QueryBus;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use App\Repository\PlaceAccessRepository;
use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageTypeRepository;
use App\Service\Security\PlaceVoter;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/places/{id}', name: 'portal_places_detail', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
#[IsGranted('ROLE_LANDLORD')]
final class PlaceDetailController extends AbstractController
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly StorageRepository $storageRepository,
        private readonly StorageTypeRepository $storageTypeRepository,
        private readonly PlaceAccessRepository $placeAccessRepository,
        private readonly ContractRepository $contractRepository,
        private readonly OrderRepository $orderRepository,
        private readonly QueryBus $queryBus,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(string $id): Response
    {
        $place = $this->placeRepository->get(Uuid::fromString($id));
        $this->denyAccessUnlessGranted(PlaceVoter::VIEW, $place);

        /** @var User $user */
        $user = $this->getUser();
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $now = $this->clock->now();

        $ownerScope = $isAdmin ? null : $user;

        $stats = $this->queryBus->handle(new GetPlaceDashboardStats(
            placeId: $place->id,
            landlordId: $isAdmin ? null : $user->id,
        ));

        $expiringContracts = $this->contractRepository
            ->findExpiringWithinDaysAtPlace(30, $now, $place, $ownerScope);
        $upcomingOrders = $this->orderRepository
            ->findUpcomingAtPlace($place, 30, $now, $ownerScope);
        $recentOrders = $this->orderRepository
            ->findRecentAtPlace($place, 5, $ownerScope);
        $freeByType = $this->storageRepository
            ->findFreeCountByTypeAtPlace($place, $ownerScope);

        $storageTypeCount = $this->storageTypeRepository->countByPlace($place);
        $storageCount = $isAdmin
            ? $this->storageRepository->countByPlace($place)
            : $this->storageRepository->countByOwnerAndPlace($user, $place);
        $hasAccess = $isAdmin || $this->placeAccessRepository->hasAccess($user, $place);

        return $this->render('portal/place/detail.html.twig', [
            'place' => $place,
            'stats' => $stats,
            'expiringContracts' => $expiringContracts,
            'upcomingOrders' => $upcomingOrders,
            'recentOrders' => $recentOrders,
            'freeByType' => $freeByType,
            'storageTypeCount' => $storageTypeCount,
            'storageCount' => $storageCount,
            'hasAccess' => $hasAccess,
            'isAdmin' => $isAdmin,
        ]);
    }
}
