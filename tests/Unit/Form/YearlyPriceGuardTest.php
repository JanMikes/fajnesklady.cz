<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Form\StorageFormData;
use App\Form\StorageTypeFormData;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * 2026-07: four storage types were saved with the per-month figure in the
 * "Sazba roční předem (Kč za rok)" field (1 350 instead of 16 200) and a
 * customer bought a whole year for 1 350 Kč.
 */
class YearlyPriceGuardTest extends TestCase
{
    public function testStorageTypeRejectsAMonthlyFigureInTheYearlyField(): void
    {
        $data = $this->storageTypeData(monthlyLongTerm: 1500.0, yearly: 1350.0);

        $violations = $this->validator()->validate($data);

        $this->assertCount(1, $violations);
        $violation = $violations->get(0);
        $this->assertSame('defaultPricePerYear', $violation->getPropertyPath());
        $this->assertStringContainsString('cena za celý rok', (string) $violation->getMessage());
    }

    public function testStorageTypeRejectsAZeroYearlyPrice(): void
    {
        $violations = $this->validator()->validate($this->storageTypeData(monthlyLongTerm: 1500.0, yearly: 0.0));

        $this->assertCount(1, $violations);
    }

    public function testStorageTypeAcceptsADiscountedYearlyPrice(): void
    {
        // 12 × 1 500 with a 10 % discount.
        $violations = $this->validator()->validate($this->storageTypeData(monthlyLongTerm: 1500.0, yearly: 16200.0));

        $this->assertCount(0, $violations);
    }

    public function testStorageTypeWithoutMonthlyPriceIsNotChecked(): void
    {
        $violations = $this->validator()->validate($this->storageTypeData(monthlyLongTerm: 0.0, yearly: 0.0));

        $this->assertCount(0, $violations);
    }

    public function testStorageOverrideIsCheckedAgainstTheTypeMonthlyRate(): void
    {
        $data = new StorageFormData();
        $data->number = 'A1';
        $data->storageTypeId = '0198a3b0-0000-7000-8000-000000000000';
        $data->typeDefaultPricePerMonthLongTerm = 1500.0;
        $data->pricePerYear = 1350.0;

        $violations = $this->validator()->validate($data);

        $this->assertCount(1, $violations);
        $this->assertSame('pricePerYear', $violations->get(0)->getPropertyPath());
    }

    public function testStorageMonthlyOverrideIsCheckedAgainstTheTypeYearlyRate(): void
    {
        // Type: 1 000/month, 10 000/year. A 5 000/month override would sell
        // a year of this storage for two months' rent.
        $data = new StorageFormData();
        $data->number = 'A1';
        $data->storageTypeId = '0198a3b0-0000-7000-8000-000000000000';
        $data->typeDefaultPricePerMonthLongTerm = 1000.0;
        $data->typeDefaultPricePerYear = 10000.0;
        $data->pricePerMonthLongTerm = 5000.0;

        $violations = $this->validator()->validate($data);

        $this->assertCount(1, $violations);
        $this->assertSame('pricePerYear', $violations->get(0)->getPropertyPath());
    }

    public function testStorageWithoutOverridesIsNotBlockedByTheTypeDefaults(): void
    {
        $data = new StorageFormData();
        $data->number = 'A1';
        $data->storageTypeId = '0198a3b0-0000-7000-8000-000000000000';
        $data->typeDefaultPricePerMonthLongTerm = 1500.0;
        $data->typeDefaultPricePerYear = 1350.0;

        $this->assertCount(0, $this->validator()->validate($data), 'the type form owns the type defaults');
    }

    public function testStorageOverrideIsSkippedWhenPricesAreNotEditable(): void
    {
        $data = new StorageFormData();
        $data->number = 'A1';
        $data->storageTypeId = '0198a3b0-0000-7000-8000-000000000000';
        $data->pricePerYear = 1350.0;

        $this->assertCount(0, $this->validator()->validate($data), 'no rendered field to attach the error to');
    }

    private function storageTypeData(float $monthlyLongTerm, float $yearly): StorageTypeFormData
    {
        $data = new StorageTypeFormData();
        $data->name = 'Box';
        $data->innerWidth = 100;
        $data->innerHeight = 100;
        $data->innerLength = 100;
        $data->defaultPricePerWeek = 500.0;
        $data->defaultPricePerMonth = 1750.0;
        $data->defaultPricePerMonthLongTerm = $monthlyLongTerm;
        $data->defaultPricePerYear = $yearly;

        return $data;
    }

    private function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }
}
