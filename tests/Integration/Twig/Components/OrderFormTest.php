<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Components;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Repository\PlatformSettingsRepository;
use App\Repository\StorageRepository;
use App\Repository\UserRepository;
use App\Service\PriceCalculator;
use App\Twig\Components\OrderForm;
use App\Value\PaymentSchedule;
use App\Value\PaymentScheduleEntry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

final class OrderFormTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private StorageRepository $storageRepository;
    private UserRepository $userRepository;
    private UrlGeneratorInterface $urlGenerator;
    private PriceCalculator $priceCalculator;
    private PlatformSettingsRepository $platformSettingsRepository;
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->storageRepository = $container->get(StorageRepository::class);
        $this->userRepository = $container->get(UserRepository::class);
        // 'router' is publicly registered and implements UrlGeneratorInterface; the interface alias
        // is private and inlined out of the test container, so we fetch the concrete service id.
        $this->urlGenerator = $container->get('router');
        $this->priceCalculator = $container->get(PriceCalculator::class);
        $this->platformSettingsRepository = $container->get(PlatformSettingsRepository::class);
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
        // A4 is MANUALLY_UNAVAILABLE per fixtures.
        $a4 = $this->findStorageByNumber('A4');

        $component = $this->makeComponent($place, $storageType, $a1);
        $component->selectStorage($a4->id->toRfc4122());

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

    private function makeComponent(Place $place, StorageType $storageType, Storage $storage): OrderForm
    {
        // selectStorage / getSelectedStorage do not need session state, so a fresh RequestStack is fine here.
        $component = new OrderForm($this->storageRepository, $this->userRepository, new RequestStack(), $this->urlGenerator, $this->priceCalculator, $this->platformSettingsRepository);
        $component->place = $place;
        $component->storageType = $storageType;
        $component->storageId = $storage->id->toRfc4122();

        return $component;
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
}
