<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use App\Repository\ContractRepository;
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
        private ContractRepository $contractRepository,
        private string $projectDir,
        private string $contractTemplatePath,
        private string $vopTemplatePath,
        private string $contractsDirectory,
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

        // Signed contract path 1: digital signature on the order itself.
        // Path 2 (migrate / paper): no order signature but the Contract carries
        // a documentPath from the admin upload — attach that instead so the
        // customer's "legal pack" inbox is complete.
        $result['hasContract'] = $this->attachContractDocument($email, $order)
            || $this->attachUploadedPaperContract($email, $order);

        // Per-order VOP: render DOCX from template, stamp signature on body
        // pages, attach PDF. Returns null when stamping fails (typically
        // missing LibreOffice or a malformed signature file).
        //
        // Migrate orders have no Order.signaturePath (the customer signed on
        // paper), but they should still receive the VOP — just without the
        // signature overlay. stampSignedPdfBytes handles null signaturePath
        // by returning the unstamped PDF.
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

    private function attachContractDocument(TemplatedEmail $email, Order $order): bool
    {
        if (!$order->hasSignature()) {
            return false;
        }

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

        return true;
    }

    private function attachUploadedPaperContract(TemplatedEmail $email, Order $order): bool
    {
        $contract = $this->contractRepository->findByOrder($order);
        if (null === $contract || !$contract->hasDocument() || null === $contract->documentPath) {
            return false;
        }

        $filePath = str_starts_with($contract->documentPath, '/')
            ? $contract->documentPath
            : $this->contractsDirectory.'/'.$contract->documentPath;

        // Canonicalize + assert the resolved path stays inside the contracts
        // directory. Defends against a malformed `documentPath` (or a future
        // bug elsewhere) shipping arbitrary files as legal-pack attachments.
        // Mirrors ContractDownloadController:54-58.
        $realPath = realpath($filePath);
        $realRoot = realpath($this->contractsDirectory);
        if (false === $realPath || false === $realRoot || !str_starts_with($realPath, $realRoot.'/')) {
            return false;
        }

        $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION) ?: 'pdf');
        $mime = match ($ext) {
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            default => 'application/octet-stream',
        };

        $documentNumber = $this->contractGenerator->formatDocumentNumberForOrder($order);
        $email->attachFromPath($realPath, sprintf('smlouva_%s.%s', $documentNumber, $ext), $mime);

        return true;
    }
}
