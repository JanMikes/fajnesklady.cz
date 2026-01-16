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
 * - {{TENANT_NAME}}, {{TENANT_EMAIL}}, {{TENANT_PHONE}}
 * - {{TENANT_COMPANY}}, {{TENANT_ICO}}, {{TENANT_DIC}}, {{TENANT_BILLING_ADDRESS}}
 * - {{STORAGE_NUMBER}}, {{STORAGE_TYPE}}, {{STORAGE_DIMENSIONS}}
 * - {{PLACE_NAME}}, {{PLACE_ADDRESS}}
 * - {{START_DATE}}, {{END_DATE}} (or "Na dobu neurčitou" for unlimited)
 * - {{RENTAL_TYPE}}, {{PRICE}}
 * - {{CONTRACT_DATE}}, {{CONTRACT_NUMBER}}
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
    public function generate(Contract $contract, string $templatePath): string
    {
        if (!file_exists($templatePath)) {
            throw new \RuntimeException(sprintf('Contract template not found: %s', $templatePath));
        }

        $templateProcessor = new TemplateProcessor($templatePath);

        // Replace all placeholders
        $this->replacePlaceholders($templateProcessor, $contract);

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
        $order = $contract->order;

        // Tenant information
        $processor->setValue('TENANT_NAME', $user->fullName);
        $processor->setValue('TENANT_EMAIL', $user->email);
        $processor->setValue('TENANT_PHONE', $user->phone ?? '-');

        // Tenant billing information
        $processor->setValue('TENANT_COMPANY', $user->companyName ?? '-');
        $processor->setValue('TENANT_ICO', $user->companyId ?? '-');
        $processor->setValue('TENANT_DIC', $user->companyVatId ?? '-');
        $processor->setValue('TENANT_BILLING_ADDRESS', $this->formatBillingAddress($user));

        // Storage information
        $processor->setValue('STORAGE_NUMBER', $storage->number);
        $processor->setValue('STORAGE_TYPE', $storageType->name);
        $processor->setValue('STORAGE_DIMENSIONS', $this->formatDimensions($storageType));

        // Place information
        $processor->setValue('PLACE_NAME', $place->name);
        $processor->setValue('PLACE_ADDRESS', $this->formatAddress($place));

        // Dates
        $processor->setValue('START_DATE', $contract->startDate->format('d.m.Y'));
        $processor->setValue('END_DATE', $this->formatEndDate($contract));

        // Rental information
        $processor->setValue('RENTAL_TYPE', $this->formatRentalType($contract->rentalType));
        $processor->setValue('PRICE', $this->formatPrice($order->totalPrice));

        // Contract metadata
        $processor->setValue('CONTRACT_DATE', $contract->createdAt->format('d.m.Y'));
        $processor->setValue('CONTRACT_NUMBER', $this->formatContractNumber($contract));
    }

    private function formatDimensions(\App\Entity\StorageType $storageType): string
    {
        // Dimensions are stored in centimeters, display in cm
        return sprintf(
            '%d × %d × %d cm',
            $storageType->innerWidth,
            $storageType->innerHeight,
            $storageType->innerLength,
        );
    }

    private function formatAddress(\App\Entity\Place $place): string
    {
        return sprintf(
            '%s, %s %s',
            $place->address,
            $place->postalCode,
            $place->city,
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

    private function formatEndDate(Contract $contract): string
    {
        if (null === $contract->endDate) {
            return 'Na dobu neurčitou';
        }

        return $contract->endDate->format('d.m.Y');
    }

    private function formatRentalType(RentalType $rentalType): string
    {
        return match ($rentalType) {
            RentalType::LIMITED => 'Doba určitá',
            RentalType::UNLIMITED => 'Doba neurčitá',
        };
    }

    private function formatPrice(int $priceInHalire): string
    {
        $priceInCzk = $priceInHalire / 100;

        return number_format($priceInCzk, 2, ',', ' ').' Kč';
    }

    private function formatContractNumber(Contract $contract): string
    {
        // Format: YYYY-MMDD-XXXXX (year-monthday-first 5 chars of UUID)
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
