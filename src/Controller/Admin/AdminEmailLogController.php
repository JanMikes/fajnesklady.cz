<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\EmailLogStatus;
use App\Repository\EmailLogRepository;
use App\Service\Form\EmailLogFilterFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/admin/email-log', name: 'admin_email_log')]
#[IsGranted('ROLE_ADMIN')]
final class AdminEmailLogController extends AbstractController
{
    private const int PAGE_SIZE = 50;

    public function __construct(
        private readonly EmailLogRepository $emailLogRepository,
        private readonly EmailLogFilterFactory $filterFactory,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', '1'));

        $filter = $this->filterFactory->fromRequest($request);

        $logs = $this->emailLogRepository->findPaginated($page, self::PAGE_SIZE, $filter);
        $totalLogs = $this->emailLogRepository->countWithFilter($filter);
        $totalPages = (int) max(1, ceil($totalLogs / self::PAGE_SIZE));

        return $this->render('admin/email_log/list.html.twig', [
            'logs' => $logs,
            'totalLogs' => $totalLogs,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'templateNames' => $this->emailLogRepository->getDistinctTemplateNames(),
            'statuses' => EmailLogStatus::cases(),
            'currentDateFrom' => null !== $request->query->get('date_from') ? (string) $request->query->get('date_from') : '',
            'currentDateTo' => null !== $request->query->get('date_to') ? (string) $request->query->get('date_to') : '',
            'currentRecipient' => $filter->recipient ?? '',
            'currentSubject' => $filter->subject ?? '',
            'currentTemplate' => $filter->templateName ?? '',
            'currentStatus' => null !== $filter->status ? $filter->status->value : '',
        ]);
    }
}
