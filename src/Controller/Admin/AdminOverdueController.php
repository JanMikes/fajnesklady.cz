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
        // Derive the summary from the views we already computed instead of
        // calling summarise($now), which would re-run findOverdueViews() (query
        // + N hydrations) a second time for the same request.
        $summary = $this->overdueChecker->summariseViews($views);

        return $this->render('admin/overdue/list.html.twig', [
            'views' => $views,
            'summary' => $summary,
        ]);
    }
}
