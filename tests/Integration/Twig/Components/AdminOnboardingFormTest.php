<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Components;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Repository\PlaceRepository;
use App\Repository\PlatformSettingsRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageTypeRepository;
use App\Service\PriceCalculator;
use App\Service\StorageAvailabilityChecker;
use App\Twig\Components\AdminOnboardingForm;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Onboarding availability gating (spec 071). The map + selection run the SAME
 * {@see StorageAvailabilityChecker} as order-acceptance enforcement: an occupied
 * or blocked unit can never be assigned (renting twice is always a mistake), and
 * nothing is selectable until a rental window is chosen.
 */
final class AdminOnboardingFormTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
    }

    public function testSelectStoragePromptsForDatesWhenNoWindowChosen(): void
    {
        [$place, $storageType] = $this->loadCentrumSmallContext();
        $a2 = $this->findStorageByNumber('A2');

        $component = $this->makeComponent($place, $storageType);
        $component->selectStorage($a2->id->toRfc4122());

        self::assertNull($component->storageId);
        self::assertSame('Nejdříve zvolte termín pronájmu (datum začátku i konce).', $component->storageError);
    }

    public function testSelectStorageRejectsStorageOfDifferentType(): void
    {
        [$place, $storageType] = $this->loadCentrumSmallContext();
        // B1 is a Medium box at the same place — wrong type for a Small onboarding.
        $b1 = $this->findStorageByNumber('B1');

        $component = $this->makeComponent($place, $storageType);
        $component->formValues = $this->windowValues();
        $component->selectStorage($b1->id->toRfc4122());

        self::assertNull($component->storageId);
    }

    public function testSelectStorageAcceptsAvailableUnitForChosenWindow(): void
    {
        [$place, $storageType] = $this->loadCentrumSmallContext();
        $a2 = $this->findStorageByNumber('A2');

        $component = $this->makeComponent($place, $storageType);
        $component->formValues = $this->windowValues();
        $component->selectStorage($a2->id->toRfc4122());

        self::assertSame($a2->id->toRfc4122(), $component->storageId);
        self::assertNull($component->storageError);
    }

    public function testSelectStorageHardBlocksUnavailableUnitForChosenWindow(): void
    {
        [$place, $storageType] = $this->loadCentrumSmallContext();
        // A4 is MANUALLY_UNAVAILABLE per fixtures — must never be assignable.
        $a4 = $this->findStorageByNumber('A4');

        $component = $this->makeComponent($place, $storageType);
        $component->formValues = $this->windowValues();
        $component->selectStorage($a4->id->toRfc4122());

        self::assertNull($component->storageId);
        self::assertNotNull($component->storageError);
        self::assertStringContainsString('obsazená nebo blokovaná', $component->storageError);
    }

    public function testGetStoragesJsonIsEmptyWithoutPlaceAndType(): void
    {
        $component = new AdminOnboardingForm(
            static::getContainer()->get(PlaceRepository::class),
            static::getContainer()->get(StorageTypeRepository::class),
            static::getContainer()->get(StorageRepository::class),
            static::getContainer()->get(PriceCalculator::class),
            static::getContainer()->get('test.command.bus'),
            static::getContainer()->get('router'),
            new NullLogger(),
            static::getContainer()->get(PlatformSettingsRepository::class),
            static::getContainer()->get(StorageAvailabilityChecker::class),
        );

        self::assertSame('[]', $component->getStoragesJson());
    }

    public function testGetStoragesJsonReflectsDerivedAvailabilityForChosenWindow(): void
    {
        [$place, $storageType] = $this->loadCentrumSmallContext();

        $component = $this->makeComponent($place, $storageType);
        $component->formValues = $this->windowValues();

        /** @var array<int, array<string, mixed>> $payload */
        $payload = json_decode($component->getStoragesJson(), true, flags: JSON_THROW_ON_ERROR);

        $byNumber = [];
        foreach ($payload as $entry) {
            $byNumber[$entry['number']] = $entry;
        }

        self::assertTrue($component->hasValidWindow());
        self::assertTrue($byNumber['A2']['available']);
        self::assertFalse($byNumber['A4']['available']);
    }

    public function testGetStoragesJsonMarksEverythingUnavailableUntilWindowChosen(): void
    {
        [$place, $storageType] = $this->loadCentrumSmallContext();

        $component = $this->makeComponent($place, $storageType);

        /** @var array<int, array<string, mixed>> $payload */
        $payload = json_decode($component->getStoragesJson(), true, flags: JSON_THROW_ON_ERROR);

        self::assertNotEmpty($payload);
        self::assertFalse($component->hasValidWindow());
        foreach ($payload as $entry) {
            self::assertFalse(
                $entry['available'],
                sprintf('Storage %s must be unavailable until a rental window is chosen.', $entry['number']),
            );
        }
    }

    private function makeComponent(Place $place, StorageType $storageType): AdminOnboardingForm
    {
        $container = static::getContainer();

        $component = new AdminOnboardingForm(
            $container->get(PlaceRepository::class),
            $container->get(StorageTypeRepository::class),
            $container->get(StorageRepository::class),
            $container->get(PriceCalculator::class),
            $container->get('test.command.bus'),
            $container->get('router'),
            new NullLogger(),
            $container->get(PlatformSettingsRepository::class),
            $container->get(StorageAvailabilityChecker::class),
        );

        $component->placeId = $place->id->toRfc4122();
        $component->storageTypeId = $storageType->id->toRfc4122();

        return $component;
    }

    /**
     * @return array<string, string>
     */
    private function windowValues(): array
    {
        return [
            'startDate' => (new \DateTimeImmutable('+10 days'))->format('Y-m-d'),
            'endDate' => (new \DateTimeImmutable('+40 days'))->format('Y-m-d'),
        ];
    }

    /**
     * @return array{Place, StorageType}
     */
    private function loadCentrumSmallContext(): array
    {
        $place = $this->entityManager->getRepository(Place::class)
            ->findOneBy(['name' => 'Sklad Praha - Centrum']);
        \assert($place instanceof Place);

        $storageType = $this->entityManager->getRepository(StorageType::class)
            ->findOneBy(['name' => 'Maly box', 'place' => $place]);
        \assert($storageType instanceof StorageType);

        return [$place, $storageType];
    }

    private function findStorageByNumber(string $number): Storage
    {
        $storage = $this->entityManager->getRepository(Storage::class)
            ->findOneBy(['number' => $number]);
        \assert($storage instanceof Storage);

        return $storage;
    }
}
