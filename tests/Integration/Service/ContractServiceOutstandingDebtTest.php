<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Service\ContractService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class ContractServiceOutstandingDebtTest extends KernelTestCase
{
    private ContractService $service;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->service = $container->get(ContractService::class);
        $this->entityManager = $container->get('doctrine')->getManager();
    }

    public function testDebtUsesIndividualMonthlyAmountWhenSet(): void
    {
        // Storage rate 1500 Kč/mo; individual override 500 Kč/mo.
        // Debt for one unpaid month is 500 Kč (the override), not 1500 Kč.
        $contract = $this->makeContract(storageRate: 150_000, individualMonthly: 50_000);
        $contract->markExternallyPrepaid(new \DateTimeImmutable('2026-01-01'));

        $debt = $this->service->calculateOutstandingDebt(
            $contract,
            new \DateTimeImmutable('2026-01-31'),
        );

        $this->assertSame(50_000, $debt);
    }

    public function testDebtFallsBackToStorageWhenNoIndividualMonthly(): void
    {
        $contract = $this->makeContract(storageRate: 150_000, individualMonthly: null);
        $contract->markExternallyPrepaid(new \DateTimeImmutable('2026-01-01'));

        $debt = $this->service->calculateOutstandingDebt(
            $contract,
            new \DateTimeImmutable('2026-01-31'),
        );

        $this->assertSame(150_000, $debt);
    }

    private function makeContract(int $storageRate, ?int $individualMonthly): Contract
    {
        $user = new User(Uuid::v7(), 'debt-'.bin2hex(random_bytes(4)).'@test.com', null, 'Test', 'User', new \DateTimeImmutable());
        $owner = new User(Uuid::v7(), 'owner-'.bin2hex(random_bytes(4)).'@test.com', null, 'Test', 'Owner', new \DateTimeImmutable());
        $this->entityManager->persist($user);
        $this->entityManager->persist($owner);

        $place = new Place(Uuid::v7(), 'P', 'A', 'Praha', '110 00', null, new \DateTimeImmutable());
        $this->entityManager->persist($place);

        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'T',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: $storageRate,
            defaultPricePerMonthLongTerm: $storageRate,
            defaultPricePerYear: $storageRate * 12,
            createdAt: new \DateTimeImmutable(),
        );
        $this->entityManager->persist($storageType);

        $storage = new Storage(
            id: Uuid::v7(),
            number: 'DEBT-'.bin2hex(random_bytes(3)),
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: new \DateTimeImmutable(),
            owner: $owner,
        );
        $this->entityManager->persist($storage);

        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: new \DateTimeImmutable('2025-12-01'),
            endDate: new \DateTimeImmutable('2026-12-01'),
            firstPaymentPrice: $individualMonthly ?? $storageRate,
            expiresAt: new \DateTimeImmutable('+7 days'),
            createdAt: new \DateTimeImmutable(),
        );
        $order->popEvents();
        $this->entityManager->persist($order);

        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $user,
            storage: $storage,
            startDate: $order->startDate,
            endDate: new \DateTimeImmutable('2026-12-01'),
            createdAt: new \DateTimeImmutable('2025-12-01'),
        );

        if (null !== $individualMonthly) {
            $contract->applyIndividualMonthlyAmount(
                $individualMonthly,
                null,
                null,
                new \DateTimeImmutable('2025-12-01'),
            );
            $contract->popEvents();
        }

        $this->entityManager->persist($contract);
        $this->entityManager->flush();

        return $contract;
    }
}
