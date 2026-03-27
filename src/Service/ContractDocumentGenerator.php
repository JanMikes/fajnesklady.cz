<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Contract;
use App\Enum\RentalType;
use PhpOffice\PhpWord\TemplateProcessor;

/**
 * Service for generating contract documents from DOCX templates.
 *
 * Replaces placeholders in the template with contract data:
 * - ${TENANT_NAME}, ${TENANT_BIRTH_DATE}, ${TENANT_ADDRESS}, ${TENANT_EMAIL}
 * - ${CONTRACT_NUMBER}, ${STORAGE_DESCRIPTION}
 * - ${RENTAL_DURATION_TEXT}
 * - ${CONTRACT_CITY}, ${CONTRACT_DATE}
 * - ${SIGNATURE} (image)
 */
final readonly class ContractDocumentGenerator
{
    public function __construct(
        private string $contractsDirectory,
    ) {
    }

    /**
     * Generate a contract document from template.
     *
     * @param Contract $contract     The contract to generate document for
     * @param string   $templatePath Path to the DOCX template file
     *
     * @return string Path to the generated document
     */
    public function generate(Contract $contract, string $templatePath, ?string $signaturePath = null): string
    {
        if (!file_exists($templatePath)) {
            throw new \RuntimeException(sprintf('Contract template not found: %s', $templatePath));
        }

        $templateProcessor = new TemplateProcessor($templatePath);

        // Replace all placeholders
        $this->replacePlaceholders($templateProcessor, $contract);

        // Embed signature image if available
        $this->embedSignature($templateProcessor, $signaturePath);

        // Generate unique filename
        $filename = $this->generateFilename($contract);
        $outputPath = $this->contractsDirectory.'/'.$filename;

        // Ensure directory exists
        $directory = dirname($outputPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Save the document
        $templateProcessor->saveAs($outputPath);

        return $outputPath;
    }

    private function replacePlaceholders(TemplateProcessor $processor, Contract $contract): void
    {
        $user = $contract->user;
        $storage = $contract->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        // Tenant information
        $processor->setValue('TENANT_NAME', $user->fullName);
        $processor->setValue('TENANT_BIRTH_DATE', $user->birthDate?->format('d.m.Y') ?? '-');
        $processor->setValue('TENANT_ADDRESS', $this->formatBillingAddress($user));
        $processor->setValue('TENANT_EMAIL', $user->email);

        // Storage description
        $processor->setValue('STORAGE_DESCRIPTION', $this->formatStorageDescription($storage, $storageType));

        // Rental duration (full sentence)
        $processor->setValue('RENTAL_DURATION_TEXT', $this->formatRentalDuration($contract));

        // Contract metadata
        $processor->setValue('CONTRACT_NUMBER', $this->formatContractNumber($contract));
        $processor->setValue('CONTRACT_CITY', $place->city);
        $processor->setValue('CONTRACT_DATE', $contract->createdAt->format('d.m.Y'));
    }

    private function embedSignature(TemplateProcessor $processor, ?string $signaturePath): void
    {
        if (null !== $signaturePath && file_exists($signaturePath)) {
            $processor->setImageValue('SIGNATURE', [
                'path' => $signaturePath,
                'width' => 200,
                'height' => 80,
                'ratio' => true,
            ]);
        } else {
            $processor->setValue('SIGNATURE', '');
        }
    }

    private function formatStorageDescription(\App\Entity\Storage $storage, \App\Entity\StorageType $storageType): string
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

    private function formatRentalDuration(Contract $contract): string
    {
        $startDate = $contract->startDate->format('d.m.Y');

        if (RentalType::UNLIMITED === $contract->rentalType || null === $contract->endDate) {
            return sprintf('Nájem se sjednává na dobu neurčitou, a to od %s', $startDate);
        }

        return sprintf(
            'Nájem se sjednává na dobu určitou, a to od %s do %s',
            $startDate,
            $contract->endDate->format('d.m.Y'),
        );
    }

    private function formatBillingAddress(\App\Entity\User $user): string
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

    private function formatContractNumber(Contract $contract): string
    {
        // Format: YYYY-MMDD-XXXXX (year-monthday-first 8 chars of UUID)
        $date = $contract->createdAt;
        $uuidShort = substr($contract->id->toRfc4122(), 0, 8);

        return sprintf(
            '%s-%s-%s',
            $date->format('Y'),
            $date->format('md'),
            strtoupper($uuidShort),
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
