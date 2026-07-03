<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Order;
use App\Entity\Storage;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Service\PriceCalculator;
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

    // Second completed order — card-recurring with a live token (storage C1 - occupied);
    // exercises the spec-076 availability guarantee (open-ended block)
    public const REF_ORDER_COMPLETED_RECURRING = 'order-completed-recurring';

    // Order in CANCELLED status (storage was released)
    public const REF_ORDER_CANCELLED = 'order-cancelled';

    // Order in EXPIRED status (storage was released)
    public const REF_ORDER_EXPIRED = 'order-expired';

    // Order expiring soon for reminder tests
    public const REF_ORDER_EXPIRING_SOON = 'order-expiring-soon';

    // Order backing the recurring contract that has a pending termination
    // notice — surfaces "ukončuje se" warnings on planning surfaces.
    public const REF_ORDER_TERMINATING = 'order-terminating';

    // Whole-rental-upfront bank transfer order (spec 078): paymentFrequency
    // ONE_TIME, billingMode ONE_TIME, firstPaymentPrice = summed monthly walk.
    public const REF_ORDER_COMPLETED_UPFRONT = 'order-completed-upfront';

    public function __construct(
        private ClockInterface $clock,
        private PriceCalculator $priceCalculator,
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
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $now->modify('+7 days'),
            endDate: $now->modify('+37 days'),
            firstPaymentPrice: 120000, // 1200 CZK
            expiresAt: $now->modify('+7 days'), // Expires in 7 days if not paid
            createdAt: $now,
        );
        $orderReserved->reserve($now);
        $orderReserved->popEvents();
        $manager->persist($orderReserved);
        $this->addReference(self::REF_ORDER_RESERVED, $orderReserved);

        // Order in PAID status - starts in 14 days, ends in 44 days
        $orderPaid = new Order(
            id: Uuid::v7(),
            user: $tenant,
            storage: $storageB2,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $now->modify('+14 days'),
            endDate: $now->modify('+44 days'),
            firstPaymentPrice: 120000,
            expiresAt: $now->modify('+7 days'),
            createdAt: $now,
        );
        $orderPaid->reserve($now);
        $orderPaid->acceptTerms($now);
        $orderPaid->markAwaitingPayment($now);
        $orderPaid->markPaid($now);
        $orderPaid->popEvents();
        $manager->persist($orderPaid);
        $this->addReference(self::REF_ORDER_PAID, $orderPaid);

        // Order in COMPLETED status - started yesterday, ends in 29 days
        // This order has an active contract (created in ContractFixtures)
        $completedOrderId = Uuid::v7();
        $orderCompleted = new Order(
            id: $completedOrderId,
            user: $user,
            storage: $storageB3,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $now->modify('-1 day'),
            endDate: $now->modify('+29 days'),
            firstPaymentPrice: 120000,
            expiresAt: $now->modify('-8 days'), // Already expired, but was paid
            createdAt: $now->modify('-8 days'),
        );
        $orderCompleted->reserve($now->modify('-8 days'));
        $orderCompleted->acceptTerms($now->modify('-8 days'));
        $orderCompleted->markAwaitingPayment($now->modify('-7 days'));
        $orderCompleted->markPaid($now->modify('-6 days'));
        // complete() will be called from ContractFixtures after contract is created
        $orderCompleted->popEvents();
        $manager->persist($orderCompleted);
        $this->addReference(self::REF_ORDER_COMPLETED, $orderCompleted);

        // Order in COMPLETED status — card-recurring, 12-month fixed term
        $recurringOrderId = Uuid::v7();
        $orderRecurring = new Order(
            id: $recurringOrderId,
            user: $user,
            storage: $storageC1,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $now->modify('-30 days'),
            // 24 months: templates gate the access code on Twig 'now' (REAL system
            // time, not MockClock), so the fixture window must outlive real today.
            endDate: $now->modify('+700 days'),
            firstPaymentPrice: 280000, // First month
            expiresAt: $now->modify('-37 days'),
            createdAt: $now->modify('-37 days'),
        );
        $orderRecurring->reserve($now->modify('-37 days'));
        $orderRecurring->acceptTerms($now->modify('-37 days'));
        $orderRecurring->markAwaitingPayment($now->modify('-36 days'));
        $orderRecurring->markPaid($now->modify('-35 days'));
        // complete() will be called from ContractFixtures after contract is created
        $orderRecurring->popEvents();
        $manager->persist($orderRecurring);
        $this->addReference(self::REF_ORDER_COMPLETED_RECURRING, $orderRecurring);

        // Order in CANCELLED status
        $orderCancelled = new Order(
            id: Uuid::v7(),
            user: $tenant,
            storage: $storageD1,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $now->modify('+7 days'),
            endDate: $now->modify('+37 days'),
            firstPaymentPrice: 40000, // 400 CZK
            expiresAt: $now->modify('+7 days'),
            createdAt: $now->modify('-2 days'),
        );
        $orderCancelled->reserve($now->modify('-2 days'));
        $orderCancelled->cancel($now->modify('-1 day'));
        $orderCancelled->popEvents();
        $manager->persist($orderCancelled);
        $this->addReference(self::REF_ORDER_CANCELLED, $orderCancelled);

        // Order in EXPIRED status
        $orderExpired = new Order(
            id: Uuid::v7(),
            user: $tenant,
            storage: $storageD2,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $now->modify('+7 days'),
            endDate: $now->modify('+37 days'),
            firstPaymentPrice: 40000,
            expiresAt: $now->modify('-1 day'), // Already expired
            createdAt: $now->modify('-8 days'),
        );
        $orderExpired->reserve($now->modify('-8 days'));
        $orderExpired->expire($now);
        $orderExpired->popEvents();
        $manager->persist($orderExpired);
        $this->addReference(self::REF_ORDER_EXPIRED, $orderExpired);

        // Order expiring soon (for testing expiration reminders)
        // This is a completed order with contract expiring in 7 days
        $expiringOrderId = Uuid::v7();
        $orderExpiringSoon = new Order(
            id: $expiringOrderId,
            user: $tenant,
            storage: $storageD3,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $now->modify('-23 days'),
            endDate: $now->modify('+7 days'), // Expires in 7 days
            firstPaymentPrice: 40000,
            expiresAt: $now->modify('-30 days'),
            createdAt: $now->modify('-30 days'),
        );
        $orderExpiringSoon->reserve($now->modify('-30 days'));
        $orderExpiringSoon->acceptTerms($now->modify('-30 days'));
        $orderExpiringSoon->markAwaitingPayment($now->modify('-29 days'));
        $orderExpiringSoon->markPaid($now->modify('-28 days'));
        // complete() will be called from ContractFixtures after contract is created
        $orderExpiringSoon->popEvents();
        $manager->persist($orderExpiringSoon);
        $this->addReference(self::REF_ORDER_EXPIRING_SOON, $orderExpiringSoon);

        // Order backing the recurring "ukončuje se" contract on storage E1
        // (Praha Jih, Medium). Started 60 days ago; the contract receives a
        // termination notice in ContractFixtures so planning surfaces render
        // the warning icon. Cannot live on a Praha Centrum small box (A-row)
        // because the admin onboarding TomSelect test relies on those staying
        // available.
        $storageForTerminating = $this->getReference(StorageFixtures::REF_MEDIUM_E1, Storage::class);
        \assert($storageForTerminating instanceof Storage);
        $orderTerminating = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storageForTerminating,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $now->modify('-60 days'),
            endDate: $now->modify('+305 days'), // 12 months from start
            firstPaymentPrice: 40000,
            expiresAt: $now->modify('-67 days'),
            createdAt: $now->modify('-67 days'),
        );
        $orderTerminating->reserve($now->modify('-67 days'));
        $orderTerminating->acceptTerms($now->modify('-67 days'));
        $orderTerminating->markAwaitingPayment($now->modify('-66 days'));
        $orderTerminating->markPaid($now->modify('-65 days'));
        $orderTerminating->popEvents();
        $manager->persist($orderTerminating);
        $this->addReference(self::REF_ORDER_TERMINATING, $orderTerminating);

        // Whole-rental-upfront bank transfer order (spec 078) on storage X2
        // (Custom box, Praha Centrum) — ~4-month span around the MockClock date,
        // paid in one bank transfer: firstPaymentPrice = whole rental total.
        $storageX2 = $this->getReference(StorageFixtures::REF_CUSTOM_X2, Storage::class);
        \assert($storageX2 instanceof Storage);
        $upfrontStart = $now->modify('-30 days');
        $upfrontEnd = $now->modify('+92 days');
        $orderUpfront = new Order(
            id: Uuid::v7(),
            user: $tenant,
            storage: $storageX2,
            paymentFrequency: PaymentFrequency::ONE_TIME,
            startDate: $upfrontStart,
            endDate: $upfrontEnd,
            firstPaymentPrice: $this->priceCalculator->calculateFirstPaymentPrice(
                $storageX2,
                $upfrontStart,
                $upfrontEnd,
                PaymentFrequency::ONE_TIME,
            ),
            expiresAt: $now->modify('-30 days'),
            createdAt: $now->modify('-37 days'),
        );
        $orderUpfront->setPaymentMethod(PaymentMethod::BANK_TRANSFER);
        $orderUpfront->setBillingMode(BillingMode::ONE_TIME);
        $orderUpfront->assignVariableSymbol('7800000001');
        $orderUpfront->reserve($now->modify('-37 days'));
        $orderUpfront->acceptTerms($now->modify('-37 days'));
        $orderUpfront->markAwaitingPayment($now->modify('-36 days'));
        $orderUpfront->markPaid($now->modify('-35 days'));
        // complete() will be called from ContractFixtures after contract is created
        $orderUpfront->popEvents();
        $manager->persist($orderUpfront);
        $this->addReference(self::REF_ORDER_COMPLETED_UPFRONT, $orderUpfront);

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
