<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Components;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Repository\StorageRepository;
use App\Service\PriceCalculator;
use App\Twig\Components\OrderForm;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class OrderFormTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private StorageRepository $storageRepository;
    private UrlGeneratorInterface $urlGenerator;
    private PriceCalculator $priceCalculator;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->storageRepository = $container->get(StorageRepository::class);
        // 'router' is publicly registered and implements UrlGeneratorInterface; the interface alias
        // is private and inlined out of the test container, so we fetch the concrete service id.
        $this->urlGenerator = $container->get('router');
        $this->priceCalculator = $container->get(PriceCalculator::class);
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

    private function makeComponent(Place $place, StorageType $storageType, Storage $storage): OrderForm
    {
        // selectStorage / getSelectedStorage do not need session state, so a fresh RequestStack is fine here.
        $component = new OrderForm($this->storageRepository, new RequestStack(), $this->urlGenerator, $this->priceCalculator);
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
