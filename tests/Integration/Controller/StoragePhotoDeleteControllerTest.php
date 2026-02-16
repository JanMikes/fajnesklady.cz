<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Storage;
use App\Entity\StoragePhoto;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class StoragePhotoDeleteControllerTest extends WebTestCase
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

    public function testAdminCanDeleteStoragePhoto(): void
    {
        $admin = $this->findUserByEmail('admin@example.com');
        $this->client->loginUser($admin, 'main');

        $storage = $this->findStorageByNumber('A1');
        $photo = $this->createStoragePhoto($storage);
        $photoId = $photo->id;

        $this->client->request('POST', '/portal/storages/'.$storage->id->toRfc4122().'/photos/'.$photoId->toRfc4122().'/delete');

        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $deletedPhoto = $this->entityManager->find(StoragePhoto::class, $photoId);
        $this->assertNull($deletedPhoto);
    }

    public function testLandlordCanDeleteOwnStoragePhoto(): void
    {
        $landlord = $this->findUserByEmail('landlord@example.com');
        $this->client->loginUser($landlord, 'main');

        // A1 is owned by landlord
        $storage = $this->findStorageByNumber('A1');
        $photo = $this->createStoragePhoto($storage);
        $photoId = $photo->id;

        $this->client->request('POST', '/portal/storages/'.$storage->id->toRfc4122().'/photos/'.$photoId->toRfc4122().'/delete');

        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $deletedPhoto = $this->entityManager->find(StoragePhoto::class, $photoId);
        $this->assertNull($deletedPhoto);
    }

    public function testLandlordCannotDeleteOtherLandlordsStoragePhoto(): void
    {
        $landlord2 = $this->findUserByEmail('landlord2@example.com');
        $this->client->loginUser($landlord2, 'main');

        // A1 is owned by landlord (not landlord2)
        $storage = $this->findStorageByNumber('A1');
        $photo = $this->createStoragePhoto($storage);

        $this->client->request('POST', '/portal/storages/'.$storage->id->toRfc4122().'/photos/'.$photo->id->toRfc4122().'/delete');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testRegularUserCannotDeleteStoragePhoto(): void
    {
        $user = $this->findUserByEmail('user@example.com');
        $this->client->loginUser($user, 'main');

        $storage = $this->findStorageByNumber('A1');
        $photo = $this->createStoragePhoto($storage);

        $this->client->request('POST', '/portal/storages/'.$storage->id->toRfc4122().'/photos/'.$photo->id->toRfc4122().'/delete');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testReturns404ForNonExistentPhoto(): void
    {
        $admin = $this->findUserByEmail('admin@example.com');
        $this->client->loginUser($admin, 'main');

        $storage = $this->findStorageByNumber('A1');
        $fakePhotoId = Uuid::v7()->toRfc4122();

        $this->client->request('POST', '/portal/storages/'.$storage->id->toRfc4122().'/photos/'.$fakePhotoId.'/delete');

        $this->assertResponseStatusCodeSame(404);
    }

    private function createStoragePhoto(Storage $storage): StoragePhoto
    {
        $photo = new StoragePhoto(
            id: Uuid::v7(),
            storage: $storage,
            path: 'storages/test/photos/test-photo.jpg',
            position: 1,
            createdAt: new \DateTimeImmutable('2025-06-15 12:00:00'),
        );

        $this->entityManager->persist($photo);
        $this->entityManager->flush();

        return $photo;
    }

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
