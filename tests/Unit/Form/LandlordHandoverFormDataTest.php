<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Enum\FineType;
use App\Form\LandlordHandoverFormData;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class LandlordHandoverFormDataTest extends TestCase
{
    public function testValidDataWithoutFinePassesValidation(): void
    {
        $data = $this->validData();

        $violations = $this->validator()->validate($data);

        self::assertCount(0, $violations);
    }

    public function testValidDataWithFinePassesValidation(): void
    {
        $data = $this->validData();
        $data->issueFine = true;
        $data->fineType = FineType::DIRTY_STORAGE;
        $data->fineAmountInCzk = 6000.0;
        $data->fineDescription = 'Znečištěná skladovací jednotka.';

        $violations = $this->validator()->validate($data);

        self::assertCount(0, $violations);
    }

    public function testFineTypeRequiredWhenIssuingFine(): void
    {
        $data = $this->validData();
        $data->issueFine = true;
        $data->fineType = null;
        $data->fineAmountInCzk = 6000.0;
        $data->fineDescription = 'Popis pokuty.';

        $violations = $this->violationsAt('fineType', $data);
        self::assertNotEmpty($violations);
        self::assertSame('Vyberte typ pokuty.', (string) $violations[0]->getMessage());
    }

    public function testFineAmountRequiredWhenIssuingFine(): void
    {
        $data = $this->validData();
        $data->issueFine = true;
        $data->fineType = FineType::OTHER;
        $data->fineAmountInCzk = null;
        $data->fineDescription = 'Popis pokuty.';

        $violations = $this->violationsAt('fineAmountInCzk', $data);
        self::assertNotEmpty($violations);
        self::assertSame('Zadejte kladnou částku pokuty.', (string) $violations[0]->getMessage());
    }

    public function testNonPositiveFineAmountIsRejected(): void
    {
        $data = $this->validData();
        $data->issueFine = true;
        $data->fineType = FineType::OTHER;
        $data->fineAmountInCzk = 0.0;
        $data->fineDescription = 'Popis pokuty.';

        $violations = $this->violationsAt('fineAmountInCzk', $data);
        self::assertNotEmpty($violations);

        $data->fineAmountInCzk = -100.0;
        $violations = $this->violationsAt('fineAmountInCzk', $data);
        self::assertNotEmpty($violations);
    }

    public function testFineDescriptionRequiredWhenIssuingFine(): void
    {
        $data = $this->validData();
        $data->issueFine = true;
        $data->fineType = FineType::DIRTY_STORAGE;
        $data->fineAmountInCzk = 6000.0;
        $data->fineDescription = '   ';

        $violations = $this->violationsAt('fineDescription', $data);
        self::assertNotEmpty($violations);
        self::assertSame('Zadejte popis pokuty.', (string) $violations[0]->getMessage());
    }

    public function testGarbageFineFieldsAreIgnoredWhenNotIssuingFine(): void
    {
        $data = $this->validData();
        $data->issueFine = false;
        $data->fineType = null;
        $data->fineAmountInCzk = -500.0;
        $data->fineDescription = '';

        $violations = $this->validator()->validate($data);

        self::assertCount(0, $violations);
    }

    /**
     * @return array<int, \Symfony\Component\Validator\ConstraintViolationInterface>
     */
    private function violationsAt(string $path, LandlordHandoverFormData $data): array
    {
        $violations = $this->validator()->validate($data);

        return array_values(array_filter(
            iterator_to_array($violations),
            static fn ($v) => $v->getPropertyPath() === $path,
        ));
    }

    private function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    private function validData(): LandlordHandoverFormData
    {
        $data = new LandlordHandoverFormData();
        $data->comment = 'Sklad předán bez závad.';

        return $data;
    }
}
