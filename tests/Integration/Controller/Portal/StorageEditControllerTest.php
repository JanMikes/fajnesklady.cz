<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Portal;

use App\Entity\Storage;
use App\Entity\StoragePhoto;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class StorageEditControllerTest extends WebTestCase
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

    public function testEditPageShowsPhotoUpload(): void
    {
        $admin = $this->findUserByEmail('admin@example.com');
        $this->client->loginUser($admin, 'main');

        $storage = $this->findStorageByNumber('A1');
        $this->client->request('GET', '/portal/storages/'.$storage->id->toRfc4122().'/edit');

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Nahrát fotografie', $body);
        $this->assertStringContainsString('multipart/form-data', $body);
    }

    public function testAdminCanUploadPhotoToSpecificStorage(): void
    {
        $admin = $this->findUserByEmail('admin@example.com');
        $this->client->loginUser($admin, 'main');

        $storage = $this->findStorageByNumber('A1');
        $createdPhotos = $this->submitPhotoUpload($storage);

        $this->assertResponseRedirects();
        $this->assertCount(1, $createdPhotos, 'Uploading on the storage edit page must create a StoragePhoto for that specific unit.');
        $this->assertSame($storage->id->toRfc4122(), $createdPhotos[0]->storage->id->toRfc4122());
    }

    public function testLandlordCanUploadPhotoToOwnStorage(): void
    {
        // A1 is owned by landlord@example.com.
        $landlord = $this->findUserByEmail('landlord@example.com');
        $this->client->loginUser($landlord, 'main');

        $storage = $this->findStorageByNumber('A1');
        $createdPhotos = $this->submitPhotoUpload($storage);

        $this->assertResponseRedirects();
        $this->assertCount(1, $createdPhotos);
    }

    public function testNonOwnerLandlordCannotEdit(): void
    {
        // A1 is owned by landlord (not landlord2).
        $landlord2 = $this->findUserByEmail('landlord2@example.com');
        $this->client->loginUser($landlord2, 'main');

        $storage = $this->findStorageByNumber('A1');
        $this->client->request('GET', '/portal/storages/'.$storage->id->toRfc4122().'/edit');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testRegularUserCannotEdit(): void
    {
        $user = $this->findUserByEmail('user@example.com');
        $this->client->loginUser($user, 'main');

        $storage = $this->findStorageByNumber('A1');
        $this->client->request('GET', '/portal/storages/'.$storage->id->toRfc4122().'/edit');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testUnauthenticatedIsRedirectedToLogin(): void
    {
        $storage = $this->findStorageByNumber('A1');
        $this->client->request('GET', '/portal/storages/'.$storage->id->toRfc4122().'/edit');

        $this->assertResponseRedirects();
        $this->assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * Submits the storage edit form with one uploaded image and returns the
     * StoragePhoto rows that now exist for the storage. Cleans up the written
     * file (the DB row is rolled back by DAMA, the filesystem is not).
     *
     * @return list<StoragePhoto>
     */
    private function submitPhotoUpload(Storage $storage): array
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_photo_').'.jpg';
        $img = imagecreatetruecolor(10, 10);
        imagejpeg($img, $tempFile);
        unset($img);

        $file = new UploadedFile($tempFile, 'unit-photo.jpg', 'image/jpeg', null, true);

        $this->client->request(
            'POST',
            '/portal/storages/'.$storage->id->toRfc4122().'/edit',
            ['storage_form' => [
                'number' => $storage->number,
                'storageTypeId' => $storage->storageType->id->toRfc4122(),
            ]],
            ['storage_form' => ['photos' => [$file]]],
        );

        $this->entityManager->clear();
        $reloaded = $this->findStorageByNumber($storage->number);
        /** @var list<StoragePhoto> $photos */
        $photos = $this->entityManager->getRepository(StoragePhoto::class)->findBy(['storage' => $reloaded]);

        $uploadsDir = static::getContainer()->getParameter('kernel.project_dir').'/public/uploads/';
        foreach ($photos as $photo) {
            $fullPath = $uploadsDir.$photo->path;
            if (is_file($fullPath)) {
                unlink($fullPath);
            }
        }

        return $photos;
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
