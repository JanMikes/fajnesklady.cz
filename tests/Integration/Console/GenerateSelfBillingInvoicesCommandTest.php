<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Place;
use App\Entity\SelfBillingInvoice;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\RentalType;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

class GenerateSelfBillingInvoicesCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Application $application;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();

        /** @var ClockInterface $clock */
        $clock = $container->get(ClockInterface::class);
        $this->clock = $clock;

        $this->application = new Application(self::$kernel);
    }

    public function testGeneratesInvoicesForLandlordWithPayments(): void
    {
        $now = $this->clock->now();
        $previousMonth = $now->modify('first day of last month');
        $year = (int) $previousMonth->format('Y');
        $month = (int) $previousMonth->format('n');

        // Create landlord with self-billing prefix
        $landlord = $this->createLandlordWithPrefix('landlord-selfbilling@test.com', 'T001');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'SB1', $landlord);

        // Create payment in the previous month
        $paymentDate = $previousMonth->modify('+5 days');
        $this->createPayment($storage, 50000, $paymentDate); // 500 CZK

        $this->entityManager->flush();

        // Run the command
        $command = $this->application->find('app:generate-self-billing-invoices');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $commandTester->assertCommandIsSuccessful();
        $output = $commandTester->getDisplay();

        $this->assertStringContainsString('[NEW]', $output);
        $this->assertStringContainsString('T001-', $output);
        $this->assertStringContainsString('1 invoice(s) created', $output);

        // Verify invoice was created
        $invoice = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(SelfBillingInvoice::class, 'i')
            ->where('i.landlord = :landlord')
            ->andWhere('i.year = :year')
            ->andWhere('i.month = :month')
            ->setParameter('landlord', $landlord)
            ->setParameter('year', $year)
            ->setParameter('month', $month)
            ->getQuery()
            ->getOneOrNullResult();

        $this->assertNotNull($invoice);
        $this->assertSame(50000, $invoice->grossAmount);
        // Default commission rate is 90%, so net = 50000 * 0.90 = 45000
        $this->assertSame(45000, $invoice->netAmount);
        $this->assertStringStartsWith('T001-', $invoice->invoiceNumber);
    }

    public function testSkipsLandlordWithoutSelfBillingPrefix(): void
    {
        $now = $this->clock->now();
        $previousMonth = $now->modify('first day of last month');

        // Create landlord without self-billing prefix
        $landlord = $this->createLandlord('landlord-noprefix@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'NOPFX1', $landlord);

        // Create payment
        $paymentDate = $previousMonth->modify('+5 days');
        $this->createPayment($storage, 50000, $paymentDate);

        $this->entityManager->flush();

        // Run the command
        $command = $this->application->find('app:generate-self-billing-invoices');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $commandTester->assertCommandIsSuccessful();
        $output = $commandTester->getDisplay();

        $this->assertStringContainsString('[SKIP]', $output);
        $this->assertStringContainsString('no self-billing prefix', $output);
    }

    public function testSkipsLandlordWithNoPayments(): void
    {
        // Create landlord with prefix but no payments
        $this->createLandlordWithPrefix('landlord-nopayments@test.com', 'T002');

        $this->entityManager->flush();

        // Run the command
        $command = $this->application->find('app:generate-self-billing-invoices');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $commandTester->assertCommandIsSuccessful();
        $output = $commandTester->getDisplay();

        $this->assertStringContainsString('[SKIP]', $output);
        $this->assertStringContainsString('no payments', $output);
    }

    public function testIdempotentInvoiceGeneration(): void
    {
        $now = $this->clock->now();
        $previousMonth = $now->modify('first day of last month');
        $year = (int) $previousMonth->format('Y');
        $month = (int) $previousMonth->format('n');

        // Create landlord with self-billing prefix and payment
        $landlord = $this->createLandlordWithPrefix('landlord-idempotent@test.com', 'T003');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'IDEM1', $landlord);

        $paymentDate = $previousMonth->modify('+5 days');
        $this->createPayment($storage, 30000, $paymentDate);

        $this->entityManager->flush();

        // Run the command first time
        $command = $this->application->find('app:generate-self-billing-invoices');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $this->assertStringContainsString('[NEW]', $commandTester->getDisplay());

        // Count invoices
        $countBefore = $this->countInvoices($landlord, $year, $month);
        $this->assertSame(1, $countBefore);

        // Run the command second time (should be idempotent)
        $commandTester2 = new CommandTester($command);
        $commandTester2->execute([]);

        $this->assertStringContainsString('[EXISTS]', $commandTester2->getDisplay());

        // Count should still be 1
        $countAfter = $this->countInvoices($landlord, $year, $month);
        $this->assertSame(1, $countAfter);
    }

    public function testCommandWithYearAndMonthOptions(): void
    {
        $now = $this->clock->now();

        // Create landlord with self-billing prefix
        $landlord = $this->createLandlordWithPrefix('landlord-options@test.com', 'T004');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'OPT1', $landlord);

        // Create payment in January 2025
        $january2025 = new \DateTimeImmutable('2025-01-15 12:00:00');
        $this->createPayment($storage, 40000, $january2025);

        $this->entityManager->flush();

        // Run the command with specific year and month
        $command = $this->application->find('app:generate-self-billing-invoices');
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            '--year' => '2025',
            '--month' => '1',
        ]);

        $commandTester->assertCommandIsSuccessful();
        $output = $commandTester->getDisplay();

        $this->assertStringContainsString('[NEW]', $output);
        $this->assertStringContainsString('T004-2025-', $output);

        // Verify invoice created for January 2025
        $invoice = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(SelfBillingInvoice::class, 'i')
            ->where('i.landlord = :landlord')
            ->andWhere('i.year = :year')
            ->andWhere('i.month = :month')
            ->setParameter('landlord', $landlord)
            ->setParameter('year', 2025)
            ->setParameter('month', 1)
            ->getQuery()
            ->getOneOrNullResult();

        $this->assertNotNull($invoice);
        $this->assertSame(2025, $invoice->year);
        $this->assertSame(1, $invoice->month);
    }

    public function testUsesStorageSpecificCommissionRate(): void
    {
        $now = $this->clock->now();
        $previousMonth = $now->modify('first day of last month');
        $year = (int) $previousMonth->format('Y');
        $month = (int) $previousMonth->format('n');

        // Create landlord with 85% default commission
        $landlord = $this->createLandlordWithPrefix('landlord-commission@test.com', 'T005');
        $landlord->updateCommissionRate('0.85', $now);

        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'COMM1', $landlord);

        // Set storage-specific commission rate to 80%
        $storage->updateCommissionRate('0.80', $now);

        // Create payment
        $paymentDate = $previousMonth->modify('+5 days');
        $this->createPayment($storage, 100000, $paymentDate); // 1000 CZK

        $this->entityManager->flush();

        // Run the command
        $command = $this->application->find('app:generate-self-billing-invoices');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $commandTester->assertCommandIsSuccessful();

        // Verify invoice uses storage-specific rate (80%)
        $invoice = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(SelfBillingInvoice::class, 'i')
            ->where('i.landlord = :landlord')
            ->andWhere('i.year = :year')
            ->andWhere('i.month = :month')
            ->setParameter('landlord', $landlord)
            ->setParameter('year', $year)
            ->setParameter('month', $month)
            ->getQuery()
            ->getOneOrNullResult();

        $this->assertNotNull($invoice);
        $this->assertSame(100000, $invoice->grossAmount);
        // Storage-specific rate 80%: 100000 * 0.80 = 80000
        $this->assertSame(80000, $invoice->netAmount);
    }

    public function testInvoiceNumberingPerLandlord(): void
    {
        $now = $this->clock->now();

        // Create two landlords with different prefixes
        $landlord1 = $this->createLandlordWithPrefix('landlord1-seq@test.com', 'T101');
        $landlord2 = $this->createLandlordWithPrefix('landlord2-seq@test.com', 'T102');

        $place = $this->createPlace();
        $storageType = $this->createStorageType();

        $storage1 = $this->createStorage($storageType, $place, 'SEQ1', $landlord1);
        $storage2 = $this->createStorage($storageType, $place, 'SEQ2', $landlord2);

        // Create payments in January 2025
        $january2025 = new \DateTimeImmutable('2025-01-15 12:00:00');
        $this->createPayment($storage1, 30000, $january2025);
        $this->createPayment($storage2, 40000, $january2025);

        $this->entityManager->flush();

        // Run the command for January 2025
        $command = $this->application->find('app:generate-self-billing-invoices');
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            '--year' => '2025',
            '--month' => '1',
        ]);

        $commandTester->assertCommandIsSuccessful();

        // Verify each landlord has their own sequence
        $invoice1 = $this->getInvoiceForLandlord($landlord1, 2025, 1);
        $invoice2 = $this->getInvoiceForLandlord($landlord2, 2025, 1);

        $this->assertNotNull($invoice1);
        $this->assertNotNull($invoice2);

        // Each should have their own prefix and start at 0001
        $this->assertSame('T101-2025-0001', $invoice1->invoiceNumber);
        $this->assertSame('T102-2025-0001', $invoice2->invoiceNumber);
    }

    private function createLandlord(string $email): User
    {
        $user = new User(
            id: Uuid::v7(),
            email: $email,
            password: 'password',
            firstName: 'Test',
            lastName: 'Landlord',
            createdAt: $this->clock->now(),
        );
        $user->markAsVerified($this->clock->now());
        $user->changeRole(UserRole::LANDLORD, $this->clock->now());
        $user->popEvents();
        $this->entityManager->persist($user);

        return $user;
    }

    private function createLandlordWithPrefix(string $email, string $prefix): User
    {
        $user = $this->createLandlord($email);
        $user->setSelfBillingPrefix($prefix, $this->clock->now());

        return $user;
    }

    private function createPlace(): Place
    {
        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place SB',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: $this->clock->now(),
        );
        $this->entityManager->persist($place);

        return $place;
    }

    private function createStorageType(): StorageType
    {
        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $this->createPlace(),
            name: 'Test Type SB',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            createdAt: $this->clock->now(),
        );
        $this->entityManager->persist($storageType);

        return $storageType;
    }

    private function createStorage(StorageType $storageType, Place $place, string $number, User $owner): Storage
    {
        $storage = new Storage(
            id: Uuid::v7(),
            number: $number,
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: $this->clock->now(),
        );
        $storage->assignOwner($owner, $this->clock->now());
        $this->entityManager->persist($storage);

        return $storage;
    }

    private function createPayment(Storage $storage, int $amount, \DateTimeImmutable $paidAt): Payment
    {
        $payment = new Payment(
            id: Uuid::v7(),
            order: null,
            contract: null,
            storage: $storage,
            amount: $amount,
            paidAt: $paidAt,
            createdAt: $paidAt,
        );
        $this->entityManager->persist($payment);

        return $payment;
    }

    private function countInvoices(User $landlord, int $year, int $month): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(SelfBillingInvoice::class, 'i')
            ->where('i.landlord = :landlord')
            ->andWhere('i.year = :year')
            ->andWhere('i.month = :month')
            ->setParameter('landlord', $landlord)
            ->setParameter('year', $year)
            ->setParameter('month', $month)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function getInvoiceForLandlord(User $landlord, int $year, int $month): ?SelfBillingInvoice
    {
        return $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(SelfBillingInvoice::class, 'i')
            ->where('i.landlord = :landlord')
            ->andWhere('i.year = :year')
            ->andWhere('i.month = :month')
            ->setParameter('landlord', $landlord)
            ->setParameter('year', $year)
            ->setParameter('month', $month)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
