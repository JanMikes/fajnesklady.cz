<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Portal;

use App\Entity\Place;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class StorageCanvasControllerTest extends WebTestCase
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

    /**
     * "Sklad Praha - Centrum" has a map image, storage types and storages, so the
     * canvas renders in its ready state. This exercises the canvas-ready branch
     * that carries the per-unit photo panel and the edit-page link template.
     */
    public function testCanvasReadyStateExposesPerStoragePhotoManagement(): void
    {
        $admin = $this->findUserByEmail('admin@example.com');
        $this->client->loginUser($admin, 'main');

        $place = $this->findPlaceByName('Sklad Praha - Centrum');
        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122().'/canvas');

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();

        // The canvas panel links to the dedicated edit page for managing photos.
        $this->assertStringContainsString('data-storage-canvas-storage-edit-url-template-value', $body);
        $this->assertStringContainsString('Spravovat fotografie', $body);
        // Each storage now carries its unit photos in the canvas payload.
        $this->assertStringContainsString('&quot;photos&quot;', $body);
    }

    private function findPlaceByName(string $name): Place
    {
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => $name]);
        \assert($place instanceof Place, sprintf('Fixture place "%s" not found', $name));

        return $place;
    }

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User, sprintf('User with email "%s" not found in fixtures', $email));

        return $user;
    }
}
