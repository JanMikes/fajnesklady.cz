<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Operations\OperationsAlertsBuilder;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/admin/operace', name: 'admin_operations')]
#[IsGranted('ROLE_ADMIN')]
final class AdminOperationsController extends AbstractController
{
    public function __construct(
        private readonly OperationsAlertsBuilder $builder,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(): Response
    {
        $now = $this->clock->now();

        return $this->render('admin/operations/list.html.twig', [
            'summary' => $this->builder->build($now),
            'now' => $now,
        ]);
    }
}
