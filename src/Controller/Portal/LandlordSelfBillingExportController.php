<?php

declare(strict_types=1);

namespace App\Controller\Portal;

use App\Entity\User;
use App\Repository\SelfBillingInvoiceRepository;
use App\Service\Excel\ExcelColumn;
use App\Service\Excel\ExcelColumnType;
use App\Service\Excel\ExcelExporter;
use App\Service\Excel\ExcelSheet;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/portal/landlord/self-billing/export', name: 'portal_landlord_self_billing_export')]
#[IsGranted('ROLE_LANDLORD')]
final class LandlordSelfBillingExportController extends AbstractController
{
    public function __construct(
        private readonly SelfBillingInvoiceRepository $selfBillingInvoiceRepository,
        private readonly ExcelExporter $excelExporter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(): Response
    {
        /** @var User $landlord */
        $landlord = $this->getUser();
        $now = $this->clock->now();

        $columns = [
            new ExcelColumn('Číslo faktury'),
            new ExcelColumn('Období'),
            new ExcelColumn('Datum vystavení', ExcelColumnType::DATE),
            new ExcelColumn('Provize (%)', ExcelColumnType::DECIMAL),
            new ExcelColumn('Hrubá částka (Kč)', ExcelColumnType::MONEY_KC),
            new ExcelColumn('K vyplacení (Kč)', ExcelColumnType::MONEY_KC),
            new ExcelColumn('Provize (Kč)', ExcelColumnType::MONEY_KC),
        ];

        $invoices = $this->selfBillingInvoiceRepository->findByLandlord($landlord);
        $rows = [];
        foreach ($invoices as $invoice) {
            $commissionAmount = $invoice->grossAmount - $invoice->netAmount;
            $rows[] = [
                $invoice->invoiceNumber,
                $invoice->getPeriodFormatted(),
                $invoice->issuedAt,
                (float) $invoice->commissionRate * 100,
                $invoice->grossAmount,
                $invoice->netAmount,
                $commissionAmount,
            ];
        }

        $sheet = new ExcelSheet(
            sheetTitle: 'Self-billing',
            filename: sprintf('self-billing-%s-%s.xlsx', self::slug($landlord->fullName), $now->format('Y-m-d')),
            columns: $columns,
            rows: $rows,
        );

        return $this->excelExporter->stream($sheet);
    }

    private static function slug(string $name): string
    {
        $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII; [:Nonspacing Mark:] Remove; Lower();', $name);
        if (false === $ascii || '' === $ascii) {
            return 'pronajimatel';
        }
        $slug = preg_replace('/[^a-z0-9]+/', '-', $ascii) ?? $ascii;
        $slug = trim($slug, '-');

        return '' === $slug ? 'pronajimatel' : $slug;
    }
}
