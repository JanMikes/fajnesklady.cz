<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\EmailLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/admin/email-log/{id}', name: 'admin_email_log_detail')]
#[IsGranted('ROLE_ADMIN')]
final class AdminEmailLogDetailController extends AbstractController
{
    public function __construct(
        private readonly EmailLogRepository $emailLogRepository,
    ) {
    }

    public function __invoke(string $id): Response
    {
        $log = $this->emailLogRepository->get(Uuid::fromString($id));

        return $this->render('admin/email_log/detail.html.twig', [
            'log' => $log,
        ]);
    }
}
