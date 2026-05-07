<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\EmailLog;
use App\Enum\EmailLogStatus;
use App\Repository\EmailLogFilter;
use App\Repository\EmailLogRepository;
use App\Service\Excel\ExcelColumn;
use App\Service\Excel\ExcelColumnType;
use App\Service\Excel\ExcelExporter;
use App\Service\Excel\ExcelSheet;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/admin/email-log/export', name: 'admin_email_log_export')]
#[IsGranted('ROLE_ADMIN')]
final class AdminEmailLogExportController extends AbstractController
{
    public function __construct(
        private readonly EmailLogRepository $emailLogRepository,
        private readonly ExcelExporter $excelExporter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $now = $this->clock->now();

        $statusValue = self::trimToNull($request->query->get('status'));
        $status = null !== $statusValue ? EmailLogStatus::tryFrom($statusValue) : null;

        $filter = new EmailLogFilter(
            dateFrom: self::parseDate($request->query->get('date_from'), endOfDay: false),
            dateTo: self::parseDate($request->query->get('date_to'), endOfDay: true),
            recipient: self::trimToNull($request->query->get('recipient')),
            subject: self::trimToNull($request->query->get('subject')),
            templateName: self::trimToNull($request->query->get('template')),
            status: $status,
        );

        $columns = [
            new ExcelColumn('Odesláno', ExcelColumnType::DATETIME),
            new ExcelColumn('Příjemce'),
            new ExcelColumn('Předmět'),
            new ExcelColumn('Šablona'),
            new ExcelColumn('Stav'),
            new ExcelColumn('Chyba'),
        ];

        $logs = $this->emailLogRepository->streamWithFilter($filter);
        $rows = (static function () use ($logs): \Generator {
            foreach ($logs as $log) {
                /** @var EmailLog $log */
                $primary = $log->toAddresses[0] ?? null;
                yield [
                    $log->attemptedAt,
                    null === $primary ? '' : (string) $primary['email'],
                    $log->subject,
                    $log->templateName,
                    $log->status->label(),
                    $log->errorMessage,
                ];
            }
        })();

        $sheet = new ExcelSheet(
            sheetTitle: 'Odchozí e-maily',
            filename: sprintf('e-maily-%s.xlsx', $now->format('Y-m-d')),
            columns: $columns,
            rows: $rows,
        );

        return $this->excelExporter->stream($sheet);
    }

    private static function trimToNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }

    private static function parseDate(mixed $value, bool $endOfDay): ?\DateTimeImmutable
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
}
