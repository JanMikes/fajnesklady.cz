<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Excel\ExcelColumn;
use App\Service\Excel\ExcelColumnType;
use App\Service\Excel\ExcelExporter;
use App\Service\Excel\ExcelSheet;
use App\Service\Overdue\OverdueChecker;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/admin/po-splatnosti/export', name: 'admin_overdue_export')]
#[IsGranted('ROLE_ADMIN')]
final class AdminOverdueExportController extends AbstractController
{
    public function __construct(
        private readonly OverdueChecker $overdueChecker,
        private readonly ExcelExporter $excelExporter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(): Response
    {
        $now = $this->clock->now();
        $views = $this->overdueChecker->findOverdueViews($now);

        $columns = [
            new ExcelColumn('Zákazník'),
            new ExcelColumn('E-mail'),
            new ExcelColumn('Telefon'),
            new ExcelColumn('Pobočka'),
            new ExcelColumn('Sklad'),
            new ExcelColumn('Důvod'),
            new ExcelColumn('Závažnost'),
            new ExcelColumn('Dní po splatnosti', ExcelColumnType::INTEGER),
            new ExcelColumn('Datum nároku', ExcelColumnType::DATE),
            new ExcelColumn('Dluh (Kč)', ExcelColumnType::MONEY_KC),
        ];

        $rows = [];
        foreach ($views as $view) {
            $rows[] = [
                $view->contract->user->fullName,
                $view->contract->user->email,
                $view->contract->user->phone,
                $view->contract->storage->place->name,
                $view->contract->storage->number,
                $view->reasonLabel,
                $view->severity->label(),
                $view->daysOverdue,
                $view->anchorDate,
                $view->overdueAmount,
            ];
        }

        $sheet = new ExcelSheet(
            sheetTitle: 'Po splatnosti',
            filename: sprintf('po-splatnosti-%s.xlsx', $now->format('Y-m-d')),
            columns: $columns,
            rows: $rows,
        );

        return $this->excelExporter->stream($sheet);
    }
}
