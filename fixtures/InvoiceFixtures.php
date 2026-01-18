<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Invoice;
use App\Entity\Order;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final class InvoiceFixtures extends Fixture implements DependentFixtureInterface
{
    public const REF_INVOICE_COMPLETED = 'invoice-completed';
    public const REF_INVOICE_UNLIMITED = 'invoice-unlimited';
    public const REF_INVOICE_EXPIRING = 'invoice-expiring';

    public function __construct(
        private ClockInterface $clock,
        private string $invoicesDirectory,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();

        // Ensure invoices directory exists
        if (!is_dir($this->invoicesDirectory)) {
            mkdir($this->invoicesDirectory, 0755, true);
        }

        /** @var Order $orderCompleted */
        $orderCompleted = $this->getReference(OrderFixtures::REF_ORDER_COMPLETED, Order::class);

        /** @var Order $orderUnlimited */
        $orderUnlimited = $this->getReference(OrderFixtures::REF_ORDER_COMPLETED_UNLIMITED, Order::class);

        /** @var Order $orderExpiringSoon */
        $orderExpiringSoon = $this->getReference(OrderFixtures::REF_ORDER_EXPIRING_SOON, Order::class);

        /** @var User $user */
        $user = $this->getReference(UserFixtures::REF_USER, User::class);

        /** @var User $tenant */
        $tenant = $this->getReference(UserFixtures::REF_TENANT, User::class);

        // Invoice for completed order
        $invoiceCompleted = new Invoice(
            id: Uuid::v7(),
            order: $orderCompleted,
            user: $user,
            fakturoidInvoiceId: 12345001,
            invoiceNumber: '2025-0001',
            amount: $orderCompleted->totalPrice,
            issuedAt: $now->modify('-5 days'),
            createdAt: $now->modify('-5 days'),
        );
        $this->attachTestPdf($invoiceCompleted);
        $invoiceCompleted->popEvents();
        $manager->persist($invoiceCompleted);
        $this->addReference(self::REF_INVOICE_COMPLETED, $invoiceCompleted);

        // Invoice for unlimited order
        $invoiceUnlimited = new Invoice(
            id: Uuid::v7(),
            order: $orderUnlimited,
            user: $user,
            fakturoidInvoiceId: 12345002,
            invoiceNumber: '2025-0002',
            amount: $orderUnlimited->totalPrice,
            issuedAt: $now->modify('-34 days'),
            createdAt: $now->modify('-34 days'),
        );
        $this->attachTestPdf($invoiceUnlimited);
        $invoiceUnlimited->popEvents();
        $manager->persist($invoiceUnlimited);
        $this->addReference(self::REF_INVOICE_UNLIMITED, $invoiceUnlimited);

        // Invoice for expiring order
        $invoiceExpiring = new Invoice(
            id: Uuid::v7(),
            order: $orderExpiringSoon,
            user: $tenant,
            fakturoidInvoiceId: 12345003,
            invoiceNumber: '2025-0003',
            amount: $orderExpiringSoon->totalPrice,
            issuedAt: $now->modify('-27 days'),
            createdAt: $now->modify('-27 days'),
        );
        $this->attachTestPdf($invoiceExpiring);
        $invoiceExpiring->popEvents();
        $manager->persist($invoiceExpiring);
        $this->addReference(self::REF_INVOICE_EXPIRING, $invoiceExpiring);

        $manager->flush();
    }

    private function attachTestPdf(Invoice $invoice): void
    {
        $pdfContent = $this->generateTestPdfContent($invoice);
        $filename = sprintf('invoice_%s_%s.pdf', $invoice->invoiceNumber, $invoice->createdAt->format('Ymd'));
        $pdfPath = $this->invoicesDirectory.'/'.$filename;

        file_put_contents($pdfPath, $pdfContent);
        $invoice->attachPdf($pdfPath);
    }

    private function generateTestPdfContent(Invoice $invoice): string
    {
        // Generate a minimal valid PDF for testing
        $content = "%PDF-1.4\n";
        $content .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $content .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
        $content .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n";

        $text = sprintf(
            "Faktura %s\nCastka: %.2f CZK\nDatum: %s",
            $invoice->invoiceNumber,
            $invoice->amount / 100,
            $invoice->issuedAt->format('d.m.Y')
        );
        $stream = "BT /F1 12 Tf 50 700 Td ({$text}) Tj ET";
        $streamLength = strlen($stream);

        $content .= "4 0 obj\n<< /Length {$streamLength} >>\nstream\n{$stream}\nendstream\nendobj\n";
        $content .= "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
        $content .= "xref\n0 6\n0000000000 65535 f \n0000000009 00000 n \n0000000058 00000 n \n0000000115 00000 n \n";
        $content .= sprintf("0000000270 00000 n \n0000000%03d 00000 n \n", 320 + $streamLength);
        $content .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n";
        $content .= sprintf("%d\n", 370 + $streamLength);
        $content .= "%%EOF";

        return $content;
    }

    /**
     * @return array<class-string<Fixture>>
     */
    public function getDependencies(): array
    {
        return [
            ContractFixtures::class,
        ];
    }
}
