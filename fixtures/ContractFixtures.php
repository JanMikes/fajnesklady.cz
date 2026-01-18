<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Storage;
use App\Entity\User;
use App\Enum\RentalType;
use App\Service\ContractDocumentGenerator;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final class ContractFixtures extends Fixture implements DependentFixtureInterface
{
    // Active contract (limited, signed)
    public const REF_CONTRACT_ACTIVE = 'contract-active';

    // Active unlimited contract (signed)
    public const REF_CONTRACT_UNLIMITED = 'contract-unlimited';

    // Contract expiring in 7 days
    public const REF_CONTRACT_EXPIRING_7_DAYS = 'contract-expiring-7-days';

    // Terminated contract
    public const REF_CONTRACT_TERMINATED = 'contract-terminated';

    public function __construct(
        private ClockInterface $clock,
        private ContractDocumentGenerator $documentGenerator,
        private string $contractTemplatePath,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();

        /** @var User $user */
        $user = $this->getReference(UserFixtures::REF_USER, User::class);

        /** @var User $tenant */
        $tenant = $this->getReference(UserFixtures::REF_TENANT, User::class);

        /** @var Order $orderCompleted */
        $orderCompleted = $this->getReference(OrderFixtures::REF_ORDER_COMPLETED, Order::class);

        /** @var Order $orderUnlimited */
        $orderUnlimited = $this->getReference(OrderFixtures::REF_ORDER_COMPLETED_UNLIMITED, Order::class);

        /** @var Order $orderExpiringSoon */
        $orderExpiringSoon = $this->getReference(OrderFixtures::REF_ORDER_EXPIRING_SOON, Order::class);

        /** @var Storage $storageB3 */
        $storageB3 = $this->getReference(StorageFixtures::REF_MEDIUM_B3, Storage::class);

        /** @var Storage $storageC1 */
        $storageC1 = $this->getReference(StorageFixtures::REF_LARGE_C1, Storage::class);

        /** @var Storage $storageD3 */
        $storageD3 = $this->getReference(StorageFixtures::REF_SMALL_D3, Storage::class);

        /** @var Storage $storageE1 */
        $storageE1 = $this->getReference(StorageFixtures::REF_MEDIUM_E1, Storage::class);

        // Contract for completed order (active, limited)
        $contractActiveId = Uuid::v7();
        $contractActive = new Contract(
            id: $contractActiveId,
            order: $orderCompleted,
            user: $user,
            storage: $storageB3,
            rentalType: RentalType::LIMITED,
            startDate: $orderCompleted->startDate,
            endDate: $orderCompleted->endDate,
            createdAt: $now->modify('-5 days'),
        );
        $contractActive->sign($now->modify('-5 days'));
        $this->generateDocument($contractActive);
        $orderCompleted->complete($contractActiveId, $now->modify('-5 days'));
        $orderCompleted->popEvents();
        $manager->persist($contractActive);
        $this->addReference(self::REF_CONTRACT_ACTIVE, $contractActive);

        // Contract for unlimited order
        $contractUnlimitedId = Uuid::v7();
        $contractUnlimited = new Contract(
            id: $contractUnlimitedId,
            order: $orderUnlimited,
            user: $user,
            storage: $storageC1,
            rentalType: RentalType::UNLIMITED,
            startDate: $orderUnlimited->startDate,
            endDate: null, // Unlimited
            createdAt: $now->modify('-34 days'),
        );
        $contractUnlimited->sign($now->modify('-34 days'));
        $this->generateDocument($contractUnlimited);
        $orderUnlimited->complete($contractUnlimitedId, $now->modify('-34 days'));
        $orderUnlimited->popEvents();
        $manager->persist($contractUnlimited);
        $this->addReference(self::REF_CONTRACT_UNLIMITED, $contractUnlimited);

        // Contract expiring in 7 days
        $contractExpiring7DaysId = Uuid::v7();
        $contractExpiring7Days = new Contract(
            id: $contractExpiring7DaysId,
            order: $orderExpiringSoon,
            user: $tenant,
            storage: $storageD3,
            rentalType: RentalType::LIMITED,
            startDate: $orderExpiringSoon->startDate,
            endDate: $orderExpiringSoon->endDate, // 7 days from now
            createdAt: $now->modify('-27 days'),
        );
        $contractExpiring7Days->sign($now->modify('-27 days'));
        $this->generateDocument($contractExpiring7Days);
        $orderExpiringSoon->complete($contractExpiring7DaysId, $now->modify('-27 days'));
        $orderExpiringSoon->popEvents();
        $manager->persist($contractExpiring7Days);
        $this->addReference(self::REF_CONTRACT_EXPIRING_7_DAYS, $contractExpiring7Days);

        // Create a terminated contract (for an older order that's not in fixtures)
        // We'll create a standalone terminated contract for testing
        $terminatedOrder = new Order(
            id: Uuid::v7(),
            user: $tenant,
            storage: $storageE1,
            rentalType: RentalType::LIMITED,
            paymentFrequency: null,
            startDate: $now->modify('-60 days'),
            endDate: $now->modify('-30 days'),
            totalPrice: 100000,
            expiresAt: $now->modify('-67 days'),
            createdAt: $now->modify('-67 days'),
        );
        $terminatedOrder->reserve($now->modify('-67 days'));
        $terminatedOrder->markAwaitingPayment($now->modify('-66 days'));
        $terminatedOrder->markPaid($now->modify('-65 days'));
        $terminatedOrder->popEvents();
        $manager->persist($terminatedOrder);

        $contractTerminatedId = Uuid::v7();
        $contractTerminated = new Contract(
            id: $contractTerminatedId,
            order: $terminatedOrder,
            user: $tenant,
            storage: $storageE1,
            rentalType: RentalType::LIMITED,
            startDate: $now->modify('-60 days'),
            endDate: $now->modify('-30 days'),
            createdAt: $now->modify('-64 days'),
        );
        $contractTerminated->sign($now->modify('-64 days'));
        $this->generateDocument($contractTerminated);
        $terminatedOrder->complete($contractTerminatedId, $now->modify('-64 days'));
        $terminatedOrder->popEvents();
        $contractTerminated->terminate($now->modify('-30 days'));
        $manager->persist($contractTerminated);
        $this->addReference(self::REF_CONTRACT_TERMINATED, $contractTerminated);

        $manager->flush();
    }

    private function generateDocument(Contract $contract): void
    {
        if (!file_exists($this->contractTemplatePath)) {
            return;
        }

        try {
            $documentPath = $this->documentGenerator->generate($contract, $this->contractTemplatePath);
            $filename = basename($documentPath);
            $contract->attachDocument($filename, $this->clock->now());
        } catch (\Exception) {
            // Ignore document generation errors in fixtures
        }
    }

    /**
     * @return array<class-string<Fixture>>
     */
    public function getDependencies(): array
    {
        return [
            OrderFixtures::class,
        ];
    }
}
