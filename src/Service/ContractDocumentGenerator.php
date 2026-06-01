<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\RentalType;
use App\Service\Order\OrderReferenceFormatter;
use PhpOffice\PhpWord\TemplateProcessor;

/**
 * Service for generating contract documents from DOCX templates.
 *
 * Replaces placeholders in the template with contract data:
 * - ${TENANT_INFO} (multiline: person or company details)
 * - ${CONTRACT_NUMBER}, ${STORAGE_DESCRIPTION}
 * - ${RENTAL_DURATION_TEXT}
 * - ${CONTRACT_CITY}, ${CONTRACT_DATE}
 * - ${SIGNING_PLACE}, ${SIGNING_DATE}
 * - ${SIGNATURE} (image)
 */
readonly class ContractDocumentGenerator
{
    public function __construct(
        private string $contractsDirectory,
        private OrderReferenceFormatter $orderReferenceFormatter,
    ) {
    }

    /**
     * Generate a contract document from template and persist it on disk.
     *
     * documentDate is sourced from $contract->order->createdAt — i.e. when
     * the customer signed and the contract was legally formed — so that the
     * persisted file (served by portal/admin download routes) carries the
     * same ${CONTRACT_DATE} as the byte-identical copy attached to the
     * order-placed and rental-activated e-mails. $contract->createdAt is the
     * internal payment-confirmation timestamp and would drift by hours-to-days.
     *
     * @return string Path to the generated document
     */
    public function generate(Contract $contract, string $templatePath, ?string $signaturePath = null, ?string $signingPlace = null, ?\DateTimeImmutable $signedAt = null): string
    {
        $bytes = $this->renderBytes(
            templatePath: $templatePath,
            documentNumber: $this->orderReferenceFormatter->format($contract->order),
            user: $contract->user,
            storage: $contract->storage,
            rentalType: $contract->rentalType,
            startDate: $contract->startDate,
            endDate: $contract->endDate,
            documentDate: $contract->order->createdAt,
            signaturePath: $signaturePath,
            signingPlace: $signingPlace,
            signedAt: $signedAt,
        );

        $outputPath = $this->contractsDirectory.'/'.$this->generateFilename($contract);

        $directory = dirname($outputPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($outputPath, $bytes);

        return $outputPath;
    }

    /**
     * Identifier printed as ${CONTRACT_NUMBER} inside the contract DOCX.
     * Exposed so e-mail attachments can use a filename that matches the
     * number the customer reads on page 1 of the document.
     */
    public function formatDocumentNumberForOrder(Order $order): string
    {
        return $this->orderReferenceFormatter->format($order);
    }

    /**
     * Render a contract document for an order and return the DOCX bytes (not persisted).
     *
     * Used to attach the signed contract to both the order-placement email (no Contract
     * entity exists yet — it's created post-payment) and the rental-activated email,
     * so both messages carry byte-identical legal artefacts.
     */
    public function renderBytesForOrder(Order $order, string $templatePath): string
    {
        return $this->renderBytes(
            templatePath: $templatePath,
            documentNumber: $this->orderReferenceFormatter->format($order),
            user: $order->user,
            storage: $order->storage,
            rentalType: $order->rentalType,
            startDate: $order->startDate,
            endDate: $order->endDate,
            documentDate: $order->createdAt,
            signaturePath: $order->signaturePath,
            signingPlace: $order->signingPlace,
            signedAt: $order->signedAt,
        );
    }

    private function renderBytes(
        string $templatePath,
        string $documentNumber,
        User $user,
        Storage $storage,
        RentalType $rentalType,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        \DateTimeImmutable $documentDate,
        ?string $signaturePath,
        ?string $signingPlace,
        ?\DateTimeImmutable $signedAt,
    ): string {
        if (!file_exists($templatePath)) {
            throw new \RuntimeException(sprintf('Contract template not found: %s', $templatePath));
        }

        $processor = new TemplateProcessor($templatePath);
        $place = $storage->getPlace();

        $processor->setValue('TENANT_INFO', $this->formatTenantInfo($user));
        $processor->setValue('STORAGE_DESCRIPTION', $this->formatStorageDescription($storage, $storage->storageType));
        $processor->setValue('RENTAL_DURATION_TEXT', $this->formatRentalDuration($rentalType, $startDate, $endDate));
        $processor->setValue('CONTRACT_NUMBER', $documentNumber);
        $processor->setValue('CONTRACT_CITY', $place->city);
        $processor->setValue('CONTRACT_DATE', $documentDate->format('d.m.Y'));
        $processor->setValue('SIGNING_PLACE', $signingPlace ?? $place->city);
        $processor->setValue('SIGNING_DATE', $signedAt?->format('d.m.Y') ?? $documentDate->format('d.m.Y'));

        $this->embedSignature($processor, $signaturePath);

        $tempPath = tempnam(sys_get_temp_dir(), 'contract_doc_');
        if (false === $tempPath) {
            throw new \RuntimeException('Unable to create temporary file for contract rendering.');
        }

        try {
            $processor->saveAs($tempPath);
            $bytes = file_get_contents($tempPath);
            if (false === $bytes) {
                throw new \RuntimeException('Unable to read rendered contract document.');
            }

            return $bytes;
        } finally {
            @unlink($tempPath);
        }
    }

    private function embedSignature(TemplateProcessor $processor, ?string $signaturePath): void
    {
        if (null !== $signaturePath && file_exists($signaturePath)) {
            $processor->setImageValue('SIGNATURE', [
                'path' => $signaturePath,
                'width' => 250,
                'height' => 100,
                'ratio' => true,
            ]);
        } else {
            $processor->setValue('SIGNATURE', '');
        }
    }

    private function formatTenantInfo(User $user): string
    {
        $address = $this->formatAddress($user);
        $phone = null !== $user->phone && '' !== $user->phone ? $user->phone : '-';

        if (null !== $user->companyName && null !== $user->companyId) {
            // Company tenant
            $lines = [
                sprintf('Společnost: %s, zastoupena %s', $user->companyName, $user->fullName),
                sprintf('IČO: %s', $user->companyId),
            ];

            if (null !== $user->companyVatId && '' !== $user->companyVatId) {
                $lines[] = sprintf('DIČ: %s', $user->companyVatId);
            }

            $lines[] = sprintf('Sídlem: %s', $address);
            $lines[] = sprintf('Email: %s', $user->email);
            $lines[] = sprintf('Telefon: %s', $phone);

            return implode("\n", $lines);
        }

        // Physical person
        $lines = [
            sprintf('Jméno: %s', $user->fullName),
            sprintf('Nar. %s', $user->birthDate?->format('d.m.Y') ?? '-'),
            sprintf('Bytem: %s', $address),
            sprintf('Email: %s', $user->email),
            sprintf('Telefon: %s', $phone),
        ];

        return implode("\n", $lines);
    }

    private function formatStorageDescription(Storage $storage, StorageType $storageType): string
    {
        return sprintf(
            '%s č. %s (%d × %d × %d cm)',
            $storageType->name,
            $storage->number,
            $storageType->innerWidth,
            $storageType->innerHeight,
            $storageType->innerLength,
        );
    }

    private function formatRentalDuration(RentalType $rentalType, \DateTimeImmutable $startDate, ?\DateTimeImmutable $endDate): string
    {
        $start = $startDate->format('d.m.Y');

        if (null === $endDate) {
            return sprintf('Nájem se sjednává na dobu určitou, a to od %s', $start);
        }

        return sprintf(
            'Nájem se sjednává na dobu určitou, a to od %s do %s',
            $start,
            $endDate->format('d.m.Y'),
        );
    }

    private function formatAddress(User $user): string
    {
        if (null === $user->billingStreet || null === $user->billingCity || null === $user->billingPostalCode) {
            return '-';
        }

        return sprintf(
            '%s, %s %s',
            $user->billingStreet,
            $user->billingPostalCode,
            $user->billingCity,
        );
    }

    private function generateFilename(Contract $contract): string
    {
        return sprintf(
            'contract_%s_%s.docx',
            $contract->id->toRfc4122(),
            $contract->createdAt->format('Ymd'),
        );
    }
}
