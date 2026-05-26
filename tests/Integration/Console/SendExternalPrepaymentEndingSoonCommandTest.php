<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Enum\RentalType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

class SendExternalPrepaymentEndingSoonCommandTest extends KernelTestCase
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

        // Wipe fixture contracts to keep the finder window clean.
        $this->entityManager->createQueryBuilder()
            ->delete(Contract::class, 'c')
            ->getQuery()
            ->execute();
    }

    public function testDispatchesNoticeForContractEndingWithinWindow(): void
    {
        $now = $this->clock->now();
        $this->createPrepaidContract('cron-ending', $now->modify('+5 days'));
        $this->entityManager->flush();

        $command = $this->application->find('app:send-external-prepayment-ending-soon');
        $tester = new CommandTester($command);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Found 1 contracts with external prepayment ending', $output);
        $this->assertStringContainsString('Notice dispatched', $output);
    }

    public function testNoNoticeWhenPrepaymentEndsBeyondWindow(): void
    {
        $now = $this->clock->now();
        $this->createPrepaidContract('cron-far-future', $now->modify('+30 days'));
        $this->entityManager->flush();

        $command = $this->application->find('app:send-external-prepayment-ending-soon');
        $tester = new CommandTester($command);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('No external prepayments end within the notice window.', $tester->getDisplay());
    }

    public function testRerunIsIdempotentSameDay(): void
    {
        $now = $this->clock->now();
        $this->createPrepaidContract('cron-idem', $now->modify('+5 days'));
        $this->entityManager->flush();

        $command = $this->application->find('app:send-external-prepayment-ending-soon');
        $tester = new CommandTester($command);

        // First run sends notice (handler marks lastAdvanceNoticeSentAt).
        $tester->execute([]);
        $this->assertStringContainsString('Found 1 contracts', $tester->getDisplay());

        $this->entityManager->clear();

        // Second run on the same day finds no work.
        $tester = new CommandTester($command);
        $tester->execute([]);
        $this->assertStringContainsString('No external prepayments end within the notice window.', $tester->getDisplay());
    }

    public function testContractsWithGoPayTokenAreSkipped(): void
    {
        $now = $this->clock->now();
        $contract = $this->createPrepaidContract('cron-has-token', $now->modify('+5 days'));
        $contract->setRecurringPayment('gp-parent-token', $now->modify('+30 days'), $now->modify('+30 days'));
        $this->entityManager->flush();

        $command = $this->application->find('app:send-external-prepayment-ending-soon');
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertStringContainsString('No external prepayments end within the notice window.', $tester->getDisplay());
    }

    private function createPrepaidContract(string $emailSeed, \DateTimeImmutable $paidThroughDate): Contract
    {
        $now = $this->clock->now();

        $user = new User(Uuid::v7(), $emailSeed.'@test.com', 'password', 'Cron', 'Tester', $now);
        $this->entityManager->persist($user);

        $place = new Place(
            id: Uuid::v7(),
            name: $emailSeed.' place',
            address: 'Testovaci 123',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: $now,
        );
        $this->entityManager->persist($place);

        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Cron Box',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            defaultPricePerMonthLongTerm: 35000,
            defaultPricePerYear: 35000 * 12,
            createdAt: $now,
        );
        $this->entityManager->persist($storageType);

        $storage = new Storage(
            id: Uuid::v7(),
            number: strtoupper(substr($emailSeed, 0, 4)),
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: $now,
        );
        $this->entityManager->persist($storage);

        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            rentalType: RentalType::UNLIMITED,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $now->modify('-90 days'),
            endDate: null,
            firstPaymentPrice: 35000,
            expiresAt: $now->modify('-83 days'),
            createdAt: $now->modify('-90 days'),
        );
        $this->entityManager->persist($order);

        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $user,
            storage: $storage,
            rentalType: RentalType::UNLIMITED,
            startDate: $now->modify('-90 days'),
            endDate: null,
            createdAt: $now->modify('-90 days'),
        );
        $contract->markExternallyPrepaid($paidThroughDate);
        $this->entityManager->persist($contract);

        return $contract;
    }
}
