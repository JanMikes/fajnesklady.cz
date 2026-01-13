<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Entity\User;
use App\Query\GetDashboardStats;
use App\Query\QueryBus;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/dashboard', name: 'portal_dashboard')]
#[IsGranted('ROLE_USER')]
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly QueryBus $queryBus,
        private readonly OrderRepository $orderRepository,
        private readonly ContractRepository $contractRepository,
        private readonly PlaceRepository $placeRepository,
        private readonly StorageRepository $storageRepository,
    ) {
    }

    public function __invoke(): Response
    {
        // Render different dashboard based on role
        if ($this->isGranted('ROLE_ADMIN')) {
            $stats = $this->queryBus->handle(new GetDashboardStats());

            return $this->render('portal/dashboard_admin.html.twig', [
                'stats' => $stats,
            ]);
        }

        if ($this->isGranted('ROLE_LANDLORD')) {
            /** @var User $landlord */
            $landlord = $this->getUser();

            $placesCount = $this->placeRepository->countByOwner($landlord);
            $totalStorages = $this->storageRepository->countByOwner($landlord);
            $occupiedStorages = $this->storageRepository->countOccupiedByOwner($landlord);
            $availableStorages = $this->storageRepository->countAvailableByOwner($landlord);
            $totalRevenue = $this->orderRepository->sumRevenueByLandlord($landlord);
            $recentOrders = $this->orderRepository->findByLandlord($landlord, 5);

            $occupancyRate = $totalStorages > 0 ? ($occupiedStorages / $totalStorages) * 100 : 0;

            return $this->render('portal/dashboard_landlord.html.twig', [
                'placesCount' => $placesCount,
                'totalStorages' => $totalStorages,
                'occupiedStorages' => $occupiedStorages,
                'availableStorages' => $availableStorages,
                'occupancyRate' => $occupancyRate,
                'totalRevenue' => $totalRevenue,
                'recentOrders' => $recentOrders,
            ]);
        }

        /** @var User $user */
        $user = $this->getUser();
        $now = new \DateTimeImmutable();

        $activeContracts = $this->contractRepository->findActiveByUser($user, $now);
        $recentOrders = $this->orderRepository->findByUser($user);

        return $this->render('portal/dashboard_user.html.twig', [
            'activeContracts' => $activeContracts,
            'recentOrders' => array_slice($recentOrders, 0, 5),
        ]);
    }
}
