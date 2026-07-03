<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Components;

use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Repository\PlatformSettingsRepository;
use App\Repository\StorageRepository;
use App\Repository\UserRepository;
use App\Service\PriceCalculator;
use App\Service\StorageAvailabilityChecker;
use App\Twig\Components\OrderForm;
use App\Value\PaymentSchedule;
use App\Value\PaymentScheduleEntry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

final class OrderFormTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->twig = $container->get('test.twig');
    }

    public function testSelectStorageSwitchesToAnotherAvailableStorageOfSameTypeAndPlace(): void
    {
        [$place, $storageType, $a1] = $this->loadCentrumSmallContext('A1');
        $a2 = $this->findStorageByNumber('A2');

        $component = $this->makeComponent($place, $storageType, $a1);
        $component->selectStorage($a2->id->toRfc4122());

        self::assertSame($a2->id->toRfc4122(), $component->storageId);
    }

    public function testSelectStorageRejectsStorageFromDifferentPlace(): void
    {
        [$place, $storageType, $a1] = $this->loadCentrumSmallContext('A1');
        // D1 is in Praha Jih (different place) — must not be accepted as a selection
        // for an order on Praha Centrum.
        $d1 = $this->findStorageByNumber('D1');

        $component = $this->makeComponent($place, $storageType, $a1);
        $component->selectStorage($d1->id->toRfc4122());

        self::assertSame($a1->id->toRfc4122(), $component->storageId);
    }

    public function testSelectStorageRejectsStorageOfDifferentType(): void
    {
        [$place, $storageType, $a1] = $this->loadCentrumSmallContext('A1');
        // B1 is in Praha Centrum but is a Medium box — must not be selectable when
        // the order is for the Small storage type.
        $b1 = $this->findStorageByNumber('B1');

        $component = $this->makeComponent($place, $storageType, $a1);
        $component->selectStorage($b1->id->toRfc4122());

        self::assertSame($a1->id->toRfc4122(), $component->storageId);
    }

    public function testSelectStorageRejectsUnavailableStorage(): void
    {
        [$place, $storageType, $a1] = $this->loadCentrumSmallContext('A1');
        // A4 is MANUALLY_UNAVAILABLE per fixtures — blocked on every date by the checker.
        $a4 = $this->findStorageByNumber('A4');

        $component = $this->makeComponent($place, $storageType, $a1);
        $component->selectStorage($a4->id->toRfc4122());

        self::assertSame($a1->id->toRfc4122(), $component->storageId);
    }

    public function testSelectStorageRejectedUntilRentalWindowChosen(): void
    {
        [$place, $storageType, $a1] = $this->loadCentrumSmallContext('A1');
        $a2 = $this->findStorageByNumber('A2');

        // No dates chosen → no usable window → nothing is selectable (Q1 gate).
        $component = $this->makeComponent($place, $storageType, $a1, []);
        $component->selectStorage($a2->id->toRfc4122());

        self::assertSame($a1->id->toRfc4122(), $component->storageId);
    }

    public function testSelectStorageIgnoresInvalidUuid(): void
    {
        [$place, $storageType, $a1] = $this->loadCentrumSmallContext('A1');

        $component = $this->makeComponent($place, $storageType, $a1);
        $component->selectStorage('not-a-uuid');

        self::assertSame($a1->id->toRfc4122(), $component->storageId);
    }

    public function testGetSelectedStorageReturnsTheCurrentStorage(): void
    {
        [$place, $storageType, $a1] = $this->loadCentrumSmallContext('A1');

        $component = $this->makeComponent($place, $storageType, $a1);

        self::assertTrue($component->getSelectedStorage()->id->equals($a1->id));
    }

    public function testGetStoragesJsonReflectsDerivedAvailabilityForChosenWindow(): void
    {
        [$place, $storageType, $a1] = $this->loadCentrumSmallContext('A1');

        $component = $this->makeComponent($place, $storageType, $a1);
        /** @var array<int, array<string, mixed>> $payload */
        $payload = json_decode($component->getStoragesJson(), true, flags: JSON_THROW_ON_ERROR);

        $byNumber = [];
        foreach ($payload as $entry) {
            $byNumber[$entry['number']] = $entry;
        }

        // A2 is free (no records) → available; A4 is MANUALLY_UNAVAILABLE → not.
        self::assertTrue($byNumber['A2']['available']);
        self::assertFalse($byNumber['A4']['available']);
        self::assertSame('manually_unavailable', $byNumber['A4']['status']);
    }

    public function testGetStoragesJsonMarksEverythingUnavailableUntilWindowChosen(): void
    {
        [$place, $storageType, $a1] = $this->loadCentrumSmallContext('A1');

        $component = $this->makeComponent($place, $storageType, $a1, []);
        /** @var array<int, array<string, mixed>> $payload */
        $payload = json_decode($component->getStoragesJson(), true, flags: JSON_THROW_ON_ERROR);

        self::assertNotEmpty($payload);
        foreach ($payload as $entry) {
            self::assertFalse(
                $entry['available'],
                sprintf('Storage %s must be unavailable until a rental window is chosen.', $entry['number']),
            );
        }
    }

    // Spec 084: units free in the window but engaged later (any future order /
    // contract / block) must not be manually pickable — auto-assign only.
    public function testGetStoragesJsonMarksEngagedButFreeUnitAsNotSelectable(): void
    {
        [$place, $storageType, $a1] = $this->loadCentrumSmallContext('A1');
        $this->createFutureOrderFor($this->findStorageByNumber('A2'));

        $component = $this->makeComponent($place, $storageType, $a1);
        /** @var array<int, array<string, mixed>> $payload */
        $payload = json_decode($component->getStoragesJson(), true, flags: JSON_THROW_ON_ERROR);

        $byNumber = [];
        foreach ($payload as $entry) {
            $byNumber[$entry['number']] = $entry;
        }

        // A2 is free in the chosen window but holds a future order → auto-assign only.
        self::assertTrue($byNumber['A2']['available']);
        self::assertFalse($byNumber['A2']['selectable']);
        // A3 has no engagement anywhere in [today, ∞) → manually pickable.
        self::assertTrue($byNumber['A3']['available']);
        self::assertTrue($byNumber['A3']['selectable']);
        // A4 is manually blocked → neither available nor selectable.
        self::assertFalse($byNumber['A4']['available']);
        self::assertFalse($byNumber['A4']['selectable']);
    }

    public function testSelectStorageRejectsEngagedButFreeUnit(): void
    {
        [$place, $storageType, $a1] = $this->loadCentrumSmallContext('A1');
        $a2 = $this->findStorageByNumber('A2');
        $this->createFutureOrderFor($a2);

        $component = $this->makeComponent($place, $storageType, $a1);
        $component->selectStorage($a2->id->toRfc4122());

        // A2 is available for the window, but its future order makes it unclean —
        // the manual pick must be silently refused (selection stays on A1).
        self::assertSame($a1->id->toRfc4122(), $component->storageId);
    }

    public function testHasValidWindowReflectsTheChosenDates(): void
    {
        [$place, $storageType, $a1] = $this->loadCentrumSmallContext('A1');

        self::assertTrue($this->makeComponent($place, $storageType, $a1)->hasValidWindow());
        self::assertFalse($this->makeComponent($place, $storageType, $a1, [])->hasValidWindow());
    }

    // Spec 042: the customer-facing schedule panel must never expose a lifetime sum across recurring charges.
    public function testOrderFormScheduleTemplateNeverShowsLifetimeSumForRecurringRental(): void
    {
        $templatePath = \dirname(__DIR__, 4).'/templates/components/OrderForm.html.twig';
        $source = file_get_contents($templatePath);
        \assert(is_string($source));

        self::assertStringNotContainsString('Cena celkem', $source);
        self::assertStringNotContainsString('totalKnownAmountInCzk', $source);
    }

    public function testScheduleFragmentForFixedTermRecurringRendersMonthlyRateNotLifetimeSum(): void
    {
        $schedule = new PaymentSchedule(
            entries: [
                new PaymentScheduleEntry(new \DateTimeImmutable('2025-06-15'), 500_000),
                new PaymentScheduleEntry(new \DateTimeImmutable('2025-07-15'), 500_000),
                new PaymentScheduleEntry(new \DateTimeImmutable('2025-08-15'), 500_000),
                new PaymentScheduleEntry(new \DateTimeImmutable('2025-09-13'), 150_000),
            ],
            isRecurring: true,
            isOpenEnded: false,
            monthlyAmount: 500_000,
        );

        // Mirrors the {% else %} branch in templates/components/OrderForm.html.twig; the source-level test above guards the live template against re-introducing the lifetime sum.
        $fragment = <<<'TWIG'
            <div>
                <div>
                    <span>Měsíční platba</span>
                    <span>{{ schedule.monthlyAmountInCzk|number_format(0, ',', ' ') }} Kč / měsíc</span>
                </div>
                <ul>
                    {% for entry in schedule.entries %}
                        <li>
                            <span>{{ loop.first ? '1. platba' : (loop.last ? 'doplatek' : (loop.index ~ '. platba')) }} ({{ entry.chargeDate|date('d.m.Y') }})</span>
                            <span>{{ entry.amountInCzk|number_format(0, ',', ' ') }} Kč</span>
                        </li>
                    {% endfor %}
                </ul>
            </div>
            TWIG;

        $rendered = $this->twig->createTemplate($fragment)->render(['schedule' => $schedule]);

        self::assertStringContainsString('Měsíční platba', $rendered);
        self::assertStringContainsString('5 000 Kč / měsíc', $rendered);
        self::assertStringContainsString('1. platba', $rendered);
        self::assertStringContainsString('2. platba', $rendered);
        self::assertStringContainsString('3. platba', $rendered);
        self::assertStringContainsString('doplatek', $rendered);
        self::assertStringContainsString('1 500 Kč', $rendered);
        self::assertStringNotContainsString('Cena celkem', $rendered);
        // Lifetime sum (3×5000 + 1500 = 16 500) must never be presented to the customer.
        self::assertStringNotContainsString('16 500', $rendered);
    }

    /**
     * @param array<string, string>|null $formValues live field values (startDate/endDate);
     *                                               null → a valid window, [] → no window
     */
    private function makeComponent(Place $place, StorageType $storageType, Storage $storage, ?array $formValues = null): OrderForm
    {
        $container = static::getContainer();
        $component = new OrderForm(
            $container->get(StorageRepository::class),
            $container->get(UserRepository::class),
            new RequestStack(),
            $container->get('router'),
            $container->get(PriceCalculator::class),
            $container->get(PlatformSettingsRepository::class),
            $container->get(StorageAvailabilityChecker::class),
            $container->get(ClockInterface::class),
        );

        $component->place = $place;
        $component->storageType = $storageType;
        $component->storageId = $storage->id->toRfc4122();
        // selectStorage / getStoragesJson resolve the window from the live form values
        // (the client's current field values), exactly as they do during a LiveAction.
        $component->formValues = $formValues ?? $this->validWindowValues();

        return $component;
    }

    /**
     * @return array<string, string>
     */
    private function validWindowValues(): array
    {
        return [
            'startDate' => (new \DateTimeImmutable('+10 days'))->format('Y-m-d'),
            'endDate' => (new \DateTimeImmutable('+40 days'))->format('Y-m-d'),
        ];
    }

    /**
     * @return array{Place, StorageType, Storage}
     */
    private function loadCentrumSmallContext(string $storageNumber): array
    {
        $place = $this->entityManager->getRepository(Place::class)
            ->findOneBy(['name' => 'Sklad Praha - Centrum']);
        \assert($place instanceof Place);

        $storageType = $this->entityManager->getRepository(StorageType::class)
            ->findOneBy(['name' => 'Maly box', 'place' => $place]);
        \assert($storageType instanceof StorageType);

        return [$place, $storageType, $this->findStorageByNumber($storageNumber)];
    }

    private function findStorageByNumber(string $number): Storage
    {
        $storage = $this->entityManager->getRepository(Storage::class)
            ->findOneBy(['number' => $number]);
        \assert($storage instanceof Storage);

        return $storage;
    }

    /**
     * Blocking order safely AFTER the test window from {@see validWindowValues()}
     * (+10 → +40 days): the unit stays available for the window but is engaged
     * in [today, ∞) — exactly the spec 084 "nelze vybrat ručně" state.
     */
    private function createFutureOrderFor(Storage $storage): void
    {
        $tenant = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => 'tenant@example.com']);
        \assert($tenant instanceof User);

        $order = new Order(
            id: Uuid::v7(),
            user: $tenant,
            storage: $storage,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: new \DateTimeImmutable('+50 days'),
            endDate: new \DateTimeImmutable('+80 days'),
            firstPaymentPrice: 50000,
            expiresAt: new \DateTimeImmutable('+7 days'),
            createdAt: new \DateTimeImmutable(),
        );
        $order->reserve(new \DateTimeImmutable());
        $this->entityManager->persist($order);
        $this->entityManager->flush();
    }
}
