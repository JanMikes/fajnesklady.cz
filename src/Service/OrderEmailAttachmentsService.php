<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use App\Service\Vop\VopDocumentGenerator;
use App\Service\Vop\VopPdfStamper;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

final readonly class OrderEmailAttachmentsService implements OrderEmailAttachments
{
    public function __construct(
        private ContractDocumentGenerator $contractGenerator,
        private DocumentPdfConverter $pdfConverter,
        private VopDocumentGenerator $vopGenerator,
        private VopPdfStamper $vopStamper,
        private string $projectDir,
        private string $contractTemplatePath,
        private string $vopTemplatePath,
    ) {
    }

    public function attachLegalDocuments(TemplatedEmail $email, Order $order): array
    {
        $result = [
            'hasContract' => false,
            'hasVop' => false,
            'hasConsumerNotice' => false,
            'hasRecurringTerms' => false,
        ];

        // Signed contract. Skipped for orders without a signature (e.g. legacy
        // admin migrations). Prefer PDF; fall back to DOCX if LibreOffice
        // conversion is unavailable on this host.
        if ($order->hasSignature()) {
            $contractBytes = $this->contractGenerator->renderBytesForOrder($order, $this->contractTemplatePath);
            $documentNumber = $this->contractGenerator->formatDocumentNumberForOrder($order);
            $pdfBytes = $this->pdfConverter->convertBytesToPdfBytes($contractBytes);

            if (null !== $pdfBytes) {
                $email->attach(
                    $pdfBytes,
                    sprintf('smlouva_%s.pdf', $documentNumber),
                    'application/pdf',
                );
            } else {
                $email->attach(
                    $contractBytes,
                    sprintf('smlouva_%s.docx', $documentNumber),
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                );
            }
            $result['hasContract'] = true;
        }

        // Per-order VOP: render DOCX from template, stamp signature on body
        // pages, attach PDF. Returns null when stamping fails (typically
        // missing LibreOffice or a malformed signature file).
        $vopDocxPath = $this->vopGenerator->generate($order, $this->vopTemplatePath);
        $vopPdfBytes = $this->vopStamper->stampSignedPdfBytes($vopDocxPath, $order->signaturePath);
        if (null !== $vopPdfBytes) {
            $email->attach(
                $vopPdfBytes,
                sprintf('vop-%s.pdf', substr($order->id->toRfc4122(), 0, 8)),
                'application/pdf',
            );
            $result['hasVop'] = true;
        }

        $consumerNoticePath = $this->projectDir.'/public/documents/pouceni-spotrebitele.pdf';
        if (file_exists($consumerNoticePath)) {
            $email->attachFromPath($consumerNoticePath, 'pouceni-spotrebitele.pdf', 'application/pdf');
            $result['hasConsumerNotice'] = true;
        }

        // Recurring conditions only apply to unlimited rentals.
        if (null === $order->endDate) {
            $recurringPaymentsPath = $this->projectDir.'/public/documents/podminky-opakovanych-plateb.pdf';
            if (file_exists($recurringPaymentsPath)) {
                $email->attachFromPath($recurringPaymentsPath, 'podminky-opakovanych-plateb.pdf', 'application/pdf');
                $result['hasRecurringTerms'] = true;
            }
        }

        return $result;
    }
}
