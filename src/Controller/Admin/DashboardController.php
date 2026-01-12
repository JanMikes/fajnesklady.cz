<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Query\GetDashboardStats;
use App\Query\QueryBus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/dashboard', name: 'admin_dashboard')]
#[IsGranted('ROLE_ADMIN')]
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly QueryBus $queryBus,
    ) {
    }

    public function __invoke(): Response
    {
        $stats = $this->queryBus->handle(new GetDashboardStats());

        return $this->render('admin/dashboard.html.twig', [
            'stats' => $stats,
        ]);
    }
}
