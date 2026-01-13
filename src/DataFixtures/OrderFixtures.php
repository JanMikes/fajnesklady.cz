<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Order;
use App\Entity\Storage;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Enum\RentalType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final class OrderFixtures extends Fixture implements DependentFixtureInterface
{
    // Order in RESERVED status (storage B1 - reserved)
    public const REF_ORDER_RESERVED = 'order-reserved';

    // Order in PAID status (storage B2 - reserved, awaiting contract)
    public const REF_ORDER_PAID = 'order-paid';

    // Order in COMPLETED status (storage B3 - occupied, has contract)
    public const REF_ORDER_COMPLETED = 'order-completed';

    // Second completed order for unlimited rental (storage C1 - occupied)
    public const REF_ORDER_COMPLETED_UNLIMITED = 'order-completed-unlimited';

    // Order in CANCELLED status (storage was released)
    public const REF_ORDER_CANCELLED = 'order-cancelled';

    // Order in EXPIRED status (storage was released)
    public const REF_ORDER_EXPIRED = 'order-expired';

    // Order expiring soon for reminder tests
    public const REF_ORDER_EXPIRING_SOON = 'order-expiring-soon';

    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();

        /** @var User $tenant */
        $tenant = $this->getReference(UserFixtures::REF_TENANT, User::class);

        /** @var User $user */
        $user = $this->getReference(UserFixtures::REF_USER, User::class);

        /** @var Storage $storageB1 */
        $storageB1 = $this->getReference(StorageFixtures::REF_MEDIUM_B1, Storage::class);

        /** @var Storage $storageB2 */
        $storageB2 = $this->getReference(StorageFixtures::REF_MEDIUM_B2, Storage::class);

        /** @var Storage $storageB3 */
        $storageB3 = $this->getReference(StorageFixtures::REF_MEDIUM_B3, Storage::class);

        /** @var Storage $storageC1 */
        $storageC1 = $this->getReference(StorageFixtures::REF_LARGE_C1, Storage::class);

        /** @var Storage $storageD1 */
        $storageD1 = $this->getReference(StorageFixtures::REF_SMALL_D1, Storage::class);

        /** @var Storage $storageD2 */
        $storageD2 = $this->getReference(StorageFixtures::REF_SMALL_D2, Storage::class);

        /** @var Storage $storageD3 */
        $storageD3 = $this->getReference(StorageFixtures::REF_SMALL_D3, Storage::class);

        // Order in RESERVED status - starts in 7 days, ends in 37 days (30 day rental)
        $orderReserved = new Order(
            id: Uuid::v7(),
            user: $tenant,
            storage: $storageB1,
            rentalType: RentalType::LIMITED,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $now->modify('+7 days'),
            endDate: $now->modify('+37 days'),
            totalPrice: 120000, // 1200 CZK
            expiresAt: $now->modify('+7 days'), // Expires in 7 days if not paid
            createdAt: $now,
        );
        $orderReserved->reserve($now);
        $manager->persist($orderReserved);
        $this->addReference(self::REF_ORDER_RESERVED, $orderReserved);

        // Order in PAID status - starts in 14 days, ends in 44 days
        $orderPaid = new Order(
            id: Uuid::v7(),
            user: $tenant,
            storage: $storageB2,
            rentalType: RentalType::LIMITED,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $now->modify('+14 days'),
            endDate: $now->modify('+44 days'),
            totalPrice: 120000,
            expiresAt: $now->modify('+7 days'),
            createdAt: $now,
        );
        $orderPaid->reserve($now);
        $orderPaid->markAwaitingPayment($now);
        $orderPaid->markPaid($now);
        $manager->persist($orderPaid);
        $this->addReference(self::REF_ORDER_PAID, $orderPaid);

        // Order in COMPLETED status - started yesterday, ends in 29 days
        // This order has an active contract (created in ContractFixtures)
        $completedOrderId = Uuid::v7();
        $orderCompleted = new Order(
            id: $completedOrderId,
            user: $user,
            storage: $storageB3,
            rentalType: RentalType::LIMITED,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $now->modify('-1 day'),
            endDate: $now->modify('+29 days'),
            totalPrice: 120000,
            expiresAt: $now->modify('-8 days'), // Already expired, but was paid
            createdAt: $now->modify('-8 days'),
        );
        $orderCompleted->reserve($now->modify('-8 days'));
        $orderCompleted->markAwaitingPayment($now->modify('-7 days'));
        $orderCompleted->markPaid($now->modify('-6 days'));
        // complete() will be called from ContractFixtures after contract is created
        $manager->persist($orderCompleted);
        $this->addReference(self::REF_ORDER_COMPLETED, $orderCompleted);

        // Order in COMPLETED status with unlimited rental
        $unlimitedOrderId = Uuid::v7();
        $orderUnlimited = new Order(
            id: $unlimitedOrderId,
            user: $user,
            storage: $storageC1,
            rentalType: RentalType::UNLIMITED,
            paymentFrequency: null,
            startDate: $now->modify('-30 days'),
            endDate: null, // Unlimited
            totalPrice: 280000, // First month
            expiresAt: $now->modify('-37 days'),
            createdAt: $now->modify('-37 days'),
        );
        $orderUnlimited->reserve($now->modify('-37 days'));
        $orderUnlimited->markAwaitingPayment($now->modify('-36 days'));
        $orderUnlimited->markPaid($now->modify('-35 days'));
        // complete() will be called from ContractFixtures after contract is created
        $manager->persist($orderUnlimited);
        $this->addReference(self::REF_ORDER_COMPLETED_UNLIMITED, $orderUnlimited);

        // Order in CANCELLED status
        $orderCancelled = new Order(
            id: Uuid::v7(),
            user: $tenant,
            storage: $storageD1,
            rentalType: RentalType::LIMITED,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $now->modify('+7 days'),
            endDate: $now->modify('+37 days'),
            totalPrice: 40000, // 400 CZK
            expiresAt: $now->modify('+7 days'),
            createdAt: $now->modify('-2 days'),
        );
        $orderCancelled->reserve($now->modify('-2 days'));
        $orderCancelled->cancel($now->modify('-1 day'));
        $manager->persist($orderCancelled);
        $this->addReference(self::REF_ORDER_CANCELLED, $orderCancelled);

        // Order in EXPIRED status
        $orderExpired = new Order(
            id: Uuid::v7(),
            user: $tenant,
            storage: $storageD2,
            rentalType: RentalType::LIMITED,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $now->modify('+7 days'),
            endDate: $now->modify('+37 days'),
            totalPrice: 40000,
            expiresAt: $now->modify('-1 day'), // Already expired
            createdAt: $now->modify('-8 days'),
        );
        $orderExpired->reserve($now->modify('-8 days'));
        $orderExpired->expire($now);
        $manager->persist($orderExpired);
        $this->addReference(self::REF_ORDER_EXPIRED, $orderExpired);

        // Order expiring soon (for testing expiration reminders)
        // This is a completed order with contract expiring in 7 days
        $expiringOrderId = Uuid::v7();
        $orderExpiringSoon = new Order(
            id: $expiringOrderId,
            user: $tenant,
            storage: $storageD3,
            rentalType: RentalType::LIMITED,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $now->modify('-23 days'),
            endDate: $now->modify('+7 days'), // Expires in 7 days
            totalPrice: 40000,
            expiresAt: $now->modify('-30 days'),
            createdAt: $now->modify('-30 days'),
        );
        $orderExpiringSoon->reserve($now->modify('-30 days'));
        $orderExpiringSoon->markAwaitingPayment($now->modify('-29 days'));
        $orderExpiringSoon->markPaid($now->modify('-28 days'));
        // complete() will be called from ContractFixtures after contract is created
        $manager->persist($orderExpiringSoon);
        $this->addReference(self::REF_ORDER_EXPIRING_SOON, $orderExpiringSoon);

        $manager->flush();
    }

    /**
     * @return array<class-string<Fixture>>
     */
    public function getDependencies(): array
    {
        return [
            StorageFixtures::class,
        ];
    }
}
