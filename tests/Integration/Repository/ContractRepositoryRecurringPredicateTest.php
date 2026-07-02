<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\DataFixtures\UserFixtures;
use App\Entity\Contract;
use App\Entity\Place;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Repository\ContractRepository;
use App\Service\OrderService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ContractRepositoryRecurringPredicateTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private OrderService $orderService;
    private ClockInterface $clock;
    private ContractRepository $contractRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
        $this->orderService = $container->get(OrderService::class);
        $this->clock = $container->get(ClockInterface::class);
        $this->contractRepository = $container->get(ContractRepository::class);
    }

    public function testManualRecurringContractsCountInActiveRecurringAll(): void
    {
        $baseline = $this->contractRepository->countActiveRecurringAll();

        $this->createContract(BillingMode::MANUAL_RECURRING);
        $this->entityManager->flush();

        self::assertSame($baseline + 1, $this->contractRepository->countActiveRecurringAll());
    }

    public function testOneTimeContractsAreExcludedFromActiveRecurring(): void
    {
        $baseline = $this->contractRepository->countActiveRecurringAll();

        $this->createContract(BillingMode::ONE_TIME);
        $this->entityManager->flush();

        self::assertSame($baseline, $this->contractRepository->countActiveRecurringAll());
    }

    public function testManualRecurringContractsContributeToExpectedMrr(): void
    {
        $baseline = $this->contractRepository->sumExpectedRecurringAll();

        $contract = $this->createContract(BillingMode::MANUAL_RECURRING);
        $this->entityManager->flush();

        self::assertSame(
            $baseline + $contract->order->firstPaymentPrice,
            $this->contractRepository->sumExpectedRecurringAll(),
        );
    }

    private function createContract(BillingMode $billingMode): Contract
    {
        /** @var User $tenant */
        $tenant = $this->entityManager->getRepository(User::class)->findOneBy(['email' => UserFixtures::TENANT_EMAIL]);
        /** @var StorageType $storageType */
        $storageType = $this->entityManager->getRepository(StorageType::class)->findOneBy(['name' => 'Maly box']);
        /** @var Place $place */
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => 'Sklad Praha - Centrum']);

        $now = $this->clock->now();

        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            $now->modify('+1 day'),
            $now->modify('+6 months'),
            $now,
            PaymentFrequency::MONTHLY,
        );
        $order->setBillingMode($billingMode);
        $order->markPaid($now);

        return $this->orderService->completeOrder($order, $now);
    }
}
