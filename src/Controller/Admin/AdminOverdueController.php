<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Overdue\OverdueChecker;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/admin/po-splatnosti', name: 'admin_overdue')]
#[IsGranted('ROLE_ADMIN')]
final class AdminOverdueController extends AbstractController
{
    public function __construct(
        private readonly OverdueChecker $overdueChecker,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(): Response
    {
        $now = $this->clock->now();
        $views = $this->overdueChecker->findOverdueViews($now);
        $summary = $this->overdueChecker->summarise($now);

        return $this->render('admin/overdue/list.html.twig', [
            'views' => $views,
            'summary' => $summary,
        ]);
    }
}
