<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Entity\User;
use App\Query\GetDashboardStats;
use App\Query\GetLandlordDashboardStats;
use App\Query\QueryBus;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use Psr\Clock\ClockInterface;
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
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(): Response
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            $stats = $this->queryBus->handle(new GetDashboardStats());

            return $this->render('portal/dashboard_admin.html.twig', [
                'stats' => $stats,
            ]);
        }

        if ($this->isGranted('ROLE_LANDLORD')) {
            /** @var User $landlord */
            $landlord = $this->getUser();

            $stats = $this->queryBus->handle(new GetLandlordDashboardStats($landlord->id));
            $recentOrders = $this->orderRepository->findByLandlord($landlord, 5);

            return $this->render('portal/dashboard_landlord.html.twig', [
                'stats' => $stats,
                'landlord' => $landlord,
                'recentOrders' => $recentOrders,
            ]);
        }

        /** @var User $user */
        $user = $this->getUser();
        $now = $this->clock->now();

        $activeContracts = $this->contractRepository->findActiveByUser($user, $now);
        $recentOrders = $this->orderRepository->findByUser($user);

        return $this->render('portal/dashboard_user.html.twig', [
            'activeContracts' => $activeContracts,
            'recentOrders' => array_slice($recentOrders, 0, 5),
        ]);
    }
}
