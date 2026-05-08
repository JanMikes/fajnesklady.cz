<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Storage;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Enum\RentalType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Spec 025 fixtures — surfaces the new admin order list filter strip and
 * order-detail banner with realistic data:
 *  - Indiv. cena (storage P1)
 *  - Zdarma (storage P2)
 *  - Externí předplatné brzy končí (storage E2)
 *  - Externí předplatné po splatnosti / Po splatnosti list (storage X3).
 *
 * Spec 030 adds:
 *  - Externí předplatné s rezervou >7 dní (storage O2) — drives the blue
 *    "Předplaceno externě do …" banner on the customer surfaces.
 */
final class OnboardingFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();

        /** @var User $tenant */
        $tenant = $this->getReference(UserFixtures::REF_TENANT, User::class);

        // Use storages that no test relies on as "available":
        //   P1, P2 (premium Brno, no owner), E2 (medium Praha Jih), X3 (custom Centrum).
        /** @var Storage $storageP1 */
        $storageP1 = $this->getReference(StorageFixtures::REF_PREMIUM_P1, Storage::class);

        /** @var Storage $storageP2 */
        $storageP2 = $this->getReference(StorageFixtures::REF_PREMIUM_P2, Storage::class);

        /** @var Storage $storageE2 */
        $storageE2 = $this->getReference(StorageFixtures::REF_MEDIUM_E2, Storage::class);

        /** @var Storage $storageX3 */
        $storageX3 = $this->getReference(StorageFixtures::REF_CUSTOM_X3, Storage::class);

        /** @var Storage $storageO2 */
        $storageO2 = $this->getReference(StorageFixtures::REF_STANDARD_O2, Storage::class);

        // 1) Individual price unlimited contract — 800 Kč/month override
        $this->createOnboardedContract(
            manager: $manager,
            user: $tenant,
            storage: $storageP1,
            now: $now,
            startedDaysAgo: 60,
            individualMonthlyAmount: 80_000,
            paidThroughDate: null,
        );

        // 2) Free unlimited contract
        $this->createOnboardedContract(
            manager: $manager,
            user: $tenant,
            storage: $storageP2,
            now: $now,
            startedDaysAgo: 30,
            individualMonthlyAmount: 0,
            paidThroughDate: null,
        );

        // 3) External prepaid, ending in 5 days — surfaces in the cron + filter strip
        $this->createOnboardedContract(
            manager: $manager,
            user: $tenant,
            storage: $storageE2,
            now: $now,
            startedDaysAgo: 90,
            individualMonthlyAmount: null,
            paidThroughDate: $now->modify('+5 days'),
        );

        // 4) External prepaid, expired 10 days ago without a GoPay token —
        // appears in Po splatnosti (spec 023 list).
        $this->createOnboardedContract(
            manager: $manager,
            user: $tenant,
            storage: $storageX3,
            now: $now,
            startedDaysAgo: 90,
            individualMonthlyAmount: null,
            paidThroughDate: $now->modify('-10 days'),
        );

        // 5) External prepaid, comfortably in the future (>7 days) — drives the
        // blue "Předplaceno externě do …" banner on the customer surfaces (spec 030).
        $this->createOnboardedContract(
            manager: $manager,
            user: $tenant,
            storage: $storageO2,
            now: $now,
            startedDaysAgo: 30,
            individualMonthlyAmount: null,
            paidThroughDate: $now->modify('+30 days'),
        );

        $manager->flush();
    }

    private function createOnboardedContract(
        ObjectManager $manager,
        User $user,
        Storage $storage,
        \DateTimeImmutable $now,
        int $startedDaysAgo,
        ?int $individualMonthlyAmount,
        ?\DateTimeImmutable $paidThroughDate,
    ): void {
        $startDate = $now->modify(sprintf('-%d days', $startedDaysAgo));
        $monthly = $individualMonthlyAmount ?? $storage->getEffectivePricePerMonth();

        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            rentalType: RentalType::UNLIMITED,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $startDate,
            endDate: null,
            firstPaymentPrice: $monthly,
            expiresAt: $startDate->modify('+30 days'),
            createdAt: $startDate->modify('-1 day'),
        );
        $order->markAsAdminCreated();
        $order->setPaymentMethod(PaymentMethod::EXTERNAL);
        $order->setOnboardingBillingTerms($individualMonthlyAmount, $paidThroughDate);
        $order->reserve($startDate);
        $order->acceptTerms($startDate);
        $order->markPaid($startDate);
        $order->popEvents();
        $manager->persist($order);

        $contractId = Uuid::v7();
        $contract = new Contract(
            id: $contractId,
            order: $order,
            user: $user,
            storage: $storage,
            rentalType: RentalType::UNLIMITED,
            startDate: $startDate,
            endDate: null,
            createdAt: $startDate,
        );

        if (null !== $individualMonthlyAmount) {
            $contract->applyIndividualMonthlyAmount($individualMonthlyAmount);
        }
        if (null !== $paidThroughDate) {
            $contract->markExternallyPrepaid($paidThroughDate);
        }
        $contract->sign($startDate);

        $order->complete($contractId, $startDate);
        $order->popEvents();

        $manager->persist($contract);
    }

    /**
     * @return array<class-string<Fixture>>
     */
    public function getDependencies(): array
    {
        return [
            ContractFixtures::class,
        ];
    }
}
