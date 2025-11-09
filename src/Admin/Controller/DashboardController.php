<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Query\GetDashboardStatsQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class DashboardController extends AbstractController
{
    public function __construct(
        private MessageBusInterface $queryBus,
    ) {
    }

    #[Route('/dashboard', name: 'admin_dashboard')]
    public function index(): Response
    {
        $envelope = $this->queryBus->dispatch(new GetDashboardStatsQuery());
        $stats = $envelope->last(HandledStamp::class)?->getResult();

        return $this->render('admin/dashboard.html.twig', [
            'stats' => $stats,
        ]);
    }
}
