<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Api;

use App\Entity\Place;
use App\Entity\PlaceStorageCodeUsage;
use App\Entity\Storage;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class StorageApiUpdateControllerTest extends WebTestCase
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

    public function testInvalidLockCodeReturns422WhenCodesEnabled(): void
    {
        $this->loginAsLandlord();
        $storage = $this->getStorageA2();

        $this->client->request(
            'PUT',
            $this->updateUrl($storage),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'number' => $storage->number,
                'storageTypeId' => $storage->storageType->id->toRfc4122(),
                'coordinates' => $storage->coordinates,
                'lockCode' => 'abcd',
            ], JSON_THROW_ON_ERROR),
        );

        $this->assertResponseStatusCodeSame(422);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('pouze číslice', $body['message']);
    }

    public function testValidLockCodePersistsAndRecordsUsage(): void
    {
        $this->loginAsLandlord();
        $storage = $this->getStorageA2();

        $this->client->request(
            'PUT',
            $this->updateUrl($storage),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'number' => $storage->number,
                'storageTypeId' => $storage->storageType->id->toRfc4122(),
                'coordinates' => $storage->coordinates,
                'lockCode' => '0123',
            ], JSON_THROW_ON_ERROR),
        );

        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Storage::class, $storage->id);
        \assert($reloaded instanceof Storage);
        $this->assertSame('0123', $reloaded->lockCode);

        $usage = $this->entityManager->getRepository(PlaceStorageCodeUsage::class)->findOneBy([
            'place' => $reloaded->place,
            'code' => '0123',
        ]);
        $this->assertInstanceOf(PlaceStorageCodeUsage::class, $usage);
    }

    public function testCodeAlreadyUsedByAnotherStorageReturns422(): void
    {
        $this->loginAsLandlord();

        // A1 already has 0042 from fixtures; saving 0042 onto A2 must fail.
        $storage = $this->getStorageA2();

        $this->client->request(
            'PUT',
            $this->updateUrl($storage),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'number' => $storage->number,
                'storageTypeId' => $storage->storageType->id->toRfc4122(),
                'coordinates' => $storage->coordinates,
                'lockCode' => '0042',
            ], JSON_THROW_ON_ERROR),
        );

        $this->assertResponseStatusCodeSame(422);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('jinému skladu', $body['message']);
    }

    public function testGenerateCodeEndpointReturnsCodeInRange(): void
    {
        $this->loginAsAdmin();
        $place = $this->getPrahaCentrum();

        $this->client->request('POST', '/api/places/'.$place->id->toRfc4122().'/storages/generate-code');

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('code', $body);
        $this->assertSame(4, strlen($body['code']));
        $this->assertTrue(ctype_digit($body['code']));
    }

    public function testGenerateCodeEndpointFailsWhenCodesDisabled(): void
    {
        $this->loginAsAdmin();
        $place = $this->getBrno();

        $this->client->request('POST', '/api/places/'.$place->id->toRfc4122().'/storages/generate-code');

        $this->assertResponseStatusCodeSame(400);
    }

    private function loginAsLandlord(): void
    {
        $landlord = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'landlord@example.com']);
        \assert($landlord instanceof User);
        $this->client->loginUser($landlord, 'main');
    }

    private function loginAsAdmin(): void
    {
        $admin = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'admin@example.com']);
        \assert($admin instanceof User);
        $this->client->loginUser($admin, 'main');
    }

    private function updateUrl(Storage $storage): string
    {
        return sprintf(
            '/api/places/%s/storages/%s',
            $storage->place->id->toRfc4122(),
            $storage->id->toRfc4122(),
        );
    }

    private function getStorageA2(): Storage
    {
        $place = $this->getPrahaCentrum();
        $storage = $this->entityManager->getRepository(Storage::class)->findOneBy([
            'place' => $place,
            'number' => 'A2',
        ]);
        \assert($storage instanceof Storage);

        return $storage;
    }

    private function getPrahaCentrum(): Place
    {
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => 'Sklad Praha - Centrum']);
        \assert($place instanceof Place);

        return $place;
    }

    private function getBrno(): Place
    {
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => 'Sklad Brno']);
        \assert($place instanceof Place);

        return $place;
    }
}
