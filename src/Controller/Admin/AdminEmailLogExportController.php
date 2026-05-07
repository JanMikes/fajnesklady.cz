<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\EmailLog;
use App\Repository\EmailLogRepository;
use App\Service\Excel\ExcelColumn;
use App\Service\Excel\ExcelColumnType;
use App\Service\Excel\ExcelExporter;
use App\Service\Excel\ExcelSheet;
use App\Service\Form\EmailLogFilterFactory;
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
        private readonly EmailLogFilterFactory $filterFactory,
        private readonly ExcelExporter $excelExporter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $now = $this->clock->now();

        $filter = $this->filterFactory->fromRequest($request);

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
}
