<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\EmailLogStatus;
use App\Repository\EmailLogFilter;
use App\Repository\EmailLogRepository;
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
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', '1'));

        $dateFrom = $this->parseDate($request->query->get('date_from'), endOfDay: false);
        $dateTo = $this->parseDate($request->query->get('date_to'), endOfDay: true);
        $recipient = $this->trimToNull($request->query->get('recipient'));
        $subject = $this->trimToNull($request->query->get('subject'));
        $templateName = $this->trimToNull($request->query->get('template'));
        $statusValue = $this->trimToNull($request->query->get('status'));

        $status = null;
        if (null !== $statusValue) {
            $status = EmailLogStatus::tryFrom($statusValue);
        }

        $filter = new EmailLogFilter(
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            recipient: $recipient,
            subject: $subject,
            templateName: $templateName,
            status: $status,
        );

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
            'currentRecipient' => $recipient ?? '',
            'currentSubject' => $subject ?? '',
            'currentTemplate' => $templateName ?? '',
            'currentStatus' => null !== $status ? $status->value : '',
        ]);
    }

    private function parseDate(mixed $value, bool $endOfDay): ?\DateTimeImmutable
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        if (false === $date) {
            return null;
        }

        return $endOfDay ? $date->setTime(23, 59, 59) : $date;
    }

    private function trimToNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
