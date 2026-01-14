<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Storage;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class StorageDeleteControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testPortalDeleteRedirectsWithErrorForOccupiedStorage(): void
    {
        $landlord = $this->findUserByEmail('landlord@example.com');
        $this->client->loginUser($landlord, 'main');

        // Storage B3 is OCCUPIED
        $storage = $this->findStorageByNumber('B3');

        $this->client->request('POST', '/portal/storages/'.$storage->id->toRfc4122().'/delete');

        $this->assertResponseRedirects();

        // Follow redirect and check for error flash
        $this->client->followRedirect();
        $this->assertSelectorExists('[data-flash-type="error"]');
    }

    public function testPortalDeleteRedirectsWithErrorForReservedStorage(): void
    {
        $landlord = $this->findUserByEmail('landlord@example.com');
        $this->client->loginUser($landlord, 'main');

        // Storage B1 is RESERVED
        $storage = $this->findStorageByNumber('B1');

        $this->client->request('POST', '/portal/storages/'.$storage->id->toRfc4122().'/delete');

        $this->assertResponseRedirects();

        // Follow redirect and check for error flash
        $this->client->followRedirect();
        $this->assertSelectorExists('[data-flash-type="error"]');
    }

    public function testPortalDeleteSucceedsForAvailableStorage(): void
    {
        $landlord = $this->findUserByEmail('landlord@example.com');
        $this->client->loginUser($landlord, 'main');

        // Storage A2 is AVAILABLE
        $storage = $this->findStorageByNumber('A2');
        $storageId = $storage->id;

        $this->client->request('POST', '/portal/storages/'.$storage->id->toRfc4122().'/delete');

        $this->assertResponseRedirects();

        // Verify storage is deleted
        $this->entityManager->clear();
        $deletedStorage = $this->entityManager->find(Storage::class, $storageId);
        $this->assertNull($deletedStorage);
    }

    // ===========================================
    // HELPER METHODS
    // ===========================================

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User, sprintf('User with email "%s" not found in fixtures', $email));

        return $user;
    }

    private function findStorageByNumber(string $number): Storage
    {
        $storage = $this->entityManager->getRepository(Storage::class)->findOneBy(['number' => $number]);
        \assert($storage instanceof Storage, sprintf('Storage with number "%s" not found in fixtures', $number));

        return $storage;
    }
}
