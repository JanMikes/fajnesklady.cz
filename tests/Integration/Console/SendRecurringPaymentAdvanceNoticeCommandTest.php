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

class SendRecurringPaymentAdvanceNoticeCommandTest extends KernelTestCase
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

        // Wipe fixture contracts so they don't bleed into the finder window.
        $this->entityManager->createQueryBuilder()
            ->delete(Contract::class, 'c')
            ->getQuery()
            ->execute();
    }

    public function testCommandDispatchesNoticeForContractInWindowWithSixMonthGap(): void
    {
        $now = $this->clock->now();

        $this->createDueContract(
            emailSeed: 'cron-due',
            lastBilledAt: $now->modify('-7 months'),
            nextBillingDate: $now->modify('+9 days'),
        );
        $this->entityManager->flush();

        $command = $this->application->find('app:send-recurring-payment-advance-notice');
        $tester = new CommandTester($command);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $output = $tester->getDisplay();

        $this->assertStringContainsString('Found 1 contracts requiring 7-day advance notice', $output);
        $this->assertStringContainsString('Notice dispatched', $output);
    }

    public function testCommandReportsNoWorkWhenNothingDue(): void
    {
        // Active contract recently charged → must NOT trigger notice.
        $now = $this->clock->now();
        $this->createDueContract(
            emailSeed: 'cron-recent',
            lastBilledAt: $now->modify('-2 months'),
            nextBillingDate: $now->modify('+9 days'),
        );
        $this->entityManager->flush();

        $command = $this->application->find('app:send-recurring-payment-advance-notice');
        $tester = new CommandTester($command);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('No contracts require advance notice', $tester->getDisplay());
    }

    private function createDueContract(
        string $emailSeed,
        \DateTimeImmutable $lastBilledAt,
        \DateTimeImmutable $nextBillingDate,
    ): Contract {
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
            startDate: $lastBilledAt->modify('-1 month'),
            endDate: null,
            firstPaymentPrice: 35000,
            expiresAt: $now->modify('+7 days'),
            createdAt: $lastBilledAt->modify('-1 month'),
        );
        $this->entityManager->persist($order);

        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $user,
            storage: $storage,
            rentalType: RentalType::UNLIMITED,
            startDate: $lastBilledAt->modify('-1 month'),
            endDate: null,
            createdAt: $lastBilledAt->modify('-1 month'),
        );
        $contract->setRecurringPayment(
            'gp-parent-'.$emailSeed,
            $nextBillingDate,
            $nextBillingDate,
        );
        $contract->recordBillingCharge($lastBilledAt, $nextBillingDate, $nextBillingDate);
        $this->entityManager->persist($contract);

        return $contract;
    }
}
