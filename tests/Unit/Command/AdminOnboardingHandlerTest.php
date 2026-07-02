<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\AdminOnboardingCommand;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AdminOnboardingHandlerTest extends TestCase
{
    public function testCommandConstructsWithAllFields(): void
    {
        $storage = $this->createStub(Storage::class);
        $storageType = $this->createStub(StorageType::class);
        $place = $this->createStub(Place::class);

        $command = new AdminOnboardingCommand(
            email: 'customer@example.com',
            firstName: 'Jan',
            lastName: 'Novák',
            phone: '+420123456789',
            birthDate: new \DateTimeImmutable('1990-01-15'),
            companyName: null,
            companyId: null,
            companyVatId: null,
            billingStreet: 'Hlavní 1',
            billingCity: 'Praha',
            billingPostalCode: '110 00',
            storage: $storage,
            storageType: $storageType,
            place: $place,
            startDate: new \DateTimeImmutable('2025-06-15'),
            endDate: new \DateTimeImmutable('2026-06-15'),
            paymentMethod: PaymentMethod::GOPAY,
            individualMonthlyAmount: null,
            paidThroughDate: null,
            createdByAdminId: Uuid::v7(),
            billingMode: BillingMode::AUTO_RECURRING,
            paymentFrequency: PaymentFrequency::MONTHLY,
        );

        self::assertSame('customer@example.com', $command->email);
        self::assertSame('Jan', $command->firstName);
        self::assertSame('2026-06-15', $command->endDate->format('Y-m-d'));
        self::assertSame(PaymentMethod::GOPAY, $command->paymentMethod);
        self::assertNull($command->variableSymbolOverride);
        self::assertNull($command->uploadedContractPath);
    }

    public function testCommandWithOptionalFields(): void
    {
        $storage = $this->createStub(Storage::class);
        $storageType = $this->createStub(StorageType::class);
        $place = $this->createStub(Place::class);

        $command = new AdminOnboardingCommand(
            email: 'customer@example.com',
            firstName: 'Jan',
            lastName: 'Novák',
            phone: null,
            birthDate: null,
            companyName: 'Firma s.r.o.',
            companyId: '12345678',
            companyVatId: 'CZ12345678',
            billingStreet: 'Hlavní 1',
            billingCity: 'Praha',
            billingPostalCode: '110 00',
            storage: $storage,
            storageType: $storageType,
            place: $place,
            startDate: new \DateTimeImmutable('2025-06-15'),
            endDate: new \DateTimeImmutable('2025-12-15'),
            paymentMethod: PaymentMethod::BANK_TRANSFER,
            individualMonthlyAmount: 150000,
            paidThroughDate: new \DateTimeImmutable('2025-09-15'),
            createdByAdminId: Uuid::v7(),
            billingMode: BillingMode::MANUAL_RECURRING,
            paymentFrequency: PaymentFrequency::MONTHLY,
            variableSymbolOverride: '9999999999',
            uploadedContractPath: '/tmp/contract.pdf',
        );

        self::assertSame('9999999999', $command->variableSymbolOverride);
        self::assertSame('/tmp/contract.pdf', $command->uploadedContractPath);
        self::assertSame(150000, $command->individualMonthlyAmount);
        self::assertSame(PaymentMethod::BANK_TRANSFER, $command->paymentMethod);
    }

    public function testFreeAmountIsZero(): void
    {
        $storage = $this->createStub(Storage::class);
        $storageType = $this->createStub(StorageType::class);
        $place = $this->createStub(Place::class);

        $command = new AdminOnboardingCommand(
            email: 'customer@example.com',
            firstName: 'Jan',
            lastName: 'Novák',
            phone: null,
            birthDate: null,
            companyName: null,
            companyId: null,
            companyVatId: null,
            billingStreet: 'Hlavní 1',
            billingCity: 'Praha',
            billingPostalCode: '110 00',
            storage: $storage,
            storageType: $storageType,
            place: $place,
            startDate: new \DateTimeImmutable('2025-06-15'),
            endDate: new \DateTimeImmutable('2026-06-15'),
            paymentMethod: PaymentMethod::EXTERNAL,
            individualMonthlyAmount: 0,
            paidThroughDate: null,
            createdByAdminId: Uuid::v7(),
            billingMode: BillingMode::MANUAL_RECURRING,
            paymentFrequency: PaymentFrequency::MONTHLY,
        );

        self::assertSame(0, $command->individualMonthlyAmount);
    }
}
