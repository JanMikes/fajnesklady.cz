<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Query\GetDashboardStats;
use App\Query\QueryBus;
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
            return $this->render('portal/dashboard_landlord.html.twig');
        }

        return $this->render('portal/dashboard_user.html.twig');
    }
}
