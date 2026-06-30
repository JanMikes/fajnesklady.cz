<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Public;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The order page is public and now renders the storage map from inside the
 * OrderForm Live Component (spec 071). These guard that it loads for anonymous
 * and authenticated visitors and rejects malformed / mismatched routes.
 */
final class OrderCreateControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testAnonymousCanLoadOrderPage(): void
    {
        [$place, $storageType, $a1] = $this->centrumSmall('A1');

        $this->client->request('GET', $this->orderUrl($place, $storageType, $a1));

        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('Vytvořit objednávku', (string) $this->client->getResponse()->getContent());
        // The map is owned by the component now — its controller mount must be present.
        self::assertSelectorExists('[data-controller~="storage-map"]');
    }

    public function testAuthenticatedUserCanLoadOrderPage(): void
    {
        [$place, $storageType, $a1] = $this->centrumSmall('A1');
        $this->client->loginUser($this->findUserByEmail('user@example.com'), 'main');

        $this->client->request('GET', $this->orderUrl($place, $storageType, $a1));

        $this->assertResponseIsSuccessful();
    }

    public function testMalformedPlaceIdReturns404(): void
    {
        [, $storageType, $a1] = $this->centrumSmall('A1');

        $this->client->request('GET', sprintf(
            '/objednavka/not-a-uuid/%s/%s',
            $storageType->id->toRfc4122(),
            $a1->id->toRfc4122(),
        ));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testUnknownPlaceReturns404(): void
    {
        [, $storageType, $a1] = $this->centrumSmall('A1');

        $this->client->request('GET', sprintf(
            '/objednavka/%s/%s/%s',
            '00000000-0000-7000-8000-000000000000',
            $storageType->id->toRfc4122(),
            $a1->id->toRfc4122(),
        ));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testStorageOfDifferentTypeReturns400(): void
    {
        [$place, $smallType] = $this->centrumSmall('A1');
        // B1 is a Medium box at the same place — it does not belong to the Small type.
        $b1 = $this->findStorageByNumber('B1');

        $this->client->request('GET', $this->orderUrl($place, $smallType, $b1));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testAdminOnlyStorageTypeReturns404(): void
    {
        // AO1 is a storage of the admin-only type at Praha Centrum — never publicly orderable.
        $ao1 = $this->findStorageByNumber('AO1');

        $this->client->request('GET', $this->orderUrl($ao1->place, $ao1->storageType, $ao1));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testMissingStorageRedirectsToFirstAvailable(): void
    {
        [$place, $storageType] = $this->centrumSmall('A1');

        $this->client->request('GET', sprintf(
            '/objednavka/%s/%s',
            $place->id->toRfc4122(),
            $storageType->id->toRfc4122(),
        ));

        $this->assertResponseRedirects();
    }

    private function orderUrl(Place $place, StorageType $storageType, Storage $storage): string
    {
        return sprintf(
            '/objednavka/%s/%s/%s',
            $place->id->toRfc4122(),
            $storageType->id->toRfc4122(),
            $storage->id->toRfc4122(),
        );
    }

    /**
     * @return array{Place, StorageType, Storage}
     */
    private function centrumSmall(string $storageNumber): array
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

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User, sprintf('User with email "%s" not found in fixtures', $email));

        return $user;
    }
}
