<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Fine;
use App\Repository\FineRepository;
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

#[Route('/portal/admin/pokuty/export', name: 'admin_fine_export')]
#[IsGranted('ROLE_ADMIN')]
final class AdminFineExportController extends AbstractController
{
    private const array VALID_STATUSES = ['unpaid', 'paid', 'cancelled'];

    public function __construct(
        private readonly FineRepository $fineRepository,
        private readonly ExcelExporter $excelExporter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $statusParam = $request->query->get('status');
        $status = is_string($statusParam) && in_array($statusParam, self::VALID_STATUSES, true) ? $statusParam : null;

        $columns = [
            new ExcelColumn('Datum vystavení', ExcelColumnType::DATETIME),
            new ExcelColumn('Zákazník'),
            new ExcelColumn('E-mail'),
            new ExcelColumn('Typ'),
            new ExcelColumn('Částka (Kč)', ExcelColumnType::MONEY_KC),
            new ExcelColumn('Stav'),
            new ExcelColumn('Způsob platby'),
            new ExcelColumn('VS'),
            new ExcelColumn('Vystavil'),
            new ExcelColumn('Poznámka'),
            new ExcelColumn('Zaplaceno', ExcelColumnType::DATETIME),
            new ExcelColumn('Zrušeno', ExcelColumnType::DATETIME),
        ];

        $fines = $this->fineRepository->findAllForExport($status);
        $rows = (static function () use ($fines): \Generator {
            foreach ($fines as $fine) {
                /** @var Fine $fine */
                $paymentMethod = '';
                if ($fine->isPaid()) {
                    $paymentMethod = null !== $fine->goPayPaymentId ? 'GoPay' : 'Bankovní převod';
                }

                $stateLabel = match (true) {
                    $fine->isPaid() => 'Zaplaceno',
                    $fine->isCancelled() => 'Zrušeno',
                    default => 'Nezaplaceno',
                };

                yield [
                    $fine->issuedAt,
                    $fine->user->fullName,
                    $fine->user->email,
                    $fine->type->label(),
                    $fine->amountInHaler,
                    $stateLabel,
                    $paymentMethod,
                    $fine->variableSymbol,
                    $fine->issuedBy->fullName,
                    $fine->description,
                    $fine->paidAt,
                    $fine->cancelledAt,
                ];
            }
        })();

        $now = $this->clock->now();
        $slug = null !== $status ? sprintf('-%s', $status) : '';
        $sheet = new ExcelSheet(
            sheetTitle: 'Pokuty',
            filename: sprintf('pokuty%s-%s.xlsx', $slug, $now->format('Y-m-d')),
            columns: $columns,
            rows: $rows,
        );

        return $this->excelExporter->stream($sheet);
    }
}
