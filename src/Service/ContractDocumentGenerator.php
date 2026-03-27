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
 * - ${TENANT_INFO} (multiline: person or company details)
 * - ${CONTRACT_NUMBER}, ${STORAGE_DESCRIPTION}
 * - ${RENTAL_DURATION_TEXT}
 * - ${CONTRACT_CITY}, ${CONTRACT_DATE}
 * - ${SIGNING_PLACE}, ${SIGNING_DATE}
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
    public function generate(Contract $contract, string $templatePath, ?string $signaturePath = null, ?string $signingPlace = null, ?\DateTimeImmutable $signedAt = null): string
    {
        if (!file_exists($templatePath)) {
            throw new \RuntimeException(sprintf('Contract template not found: %s', $templatePath));
        }

        $templateProcessor = new TemplateProcessor($templatePath);

        // Replace all placeholders
        $this->replacePlaceholders($templateProcessor, $contract, $signingPlace, $signedAt);

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

    private function replacePlaceholders(TemplateProcessor $processor, Contract $contract, ?string $signingPlace, ?\DateTimeImmutable $signedAt): void
    {
        $user = $contract->user;
        $storage = $contract->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        // Tenant information (multiline, adapts to person vs company)
        $processor->setValue('TENANT_INFO', $this->formatTenantInfo($user));

        // Storage description
        $processor->setValue('STORAGE_DESCRIPTION', $this->formatStorageDescription($storage, $storageType));

        // Rental duration (full sentence)
        $processor->setValue('RENTAL_DURATION_TEXT', $this->formatRentalDuration($contract));

        // Contract metadata
        $processor->setValue('CONTRACT_NUMBER', $this->formatContractNumber($contract));
        $processor->setValue('CONTRACT_CITY', $place->city);
        $processor->setValue('CONTRACT_DATE', $contract->createdAt->format('d.m.Y'));

        // Signing metadata
        $processor->setValue('SIGNING_PLACE', $signingPlace ?? $place->city);
        $processor->setValue('SIGNING_DATE', $signedAt?->format('d.m.Y') ?? $contract->createdAt->format('d.m.Y'));
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

    private function formatTenantInfo(\App\Entity\User $user): string
    {
        $address = $this->formatAddress($user);

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

            return implode("\n", $lines);
        }

        // Physical person
        $lines = [
            sprintf('Jméno: %s', $user->fullName),
            sprintf('Nar. %s', $user->birthDate?->format('d.m.Y') ?? '-'),
            sprintf('Bytem: %s', $address),
            sprintf('Email: %s', $user->email),
        ];

        return implode("\n", $lines);
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

    private function formatAddress(\App\Entity\User $user): string
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
