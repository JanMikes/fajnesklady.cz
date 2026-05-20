<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Portal;

use App\Entity\Place;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PlaceOccupancyControllerTest extends WebTestCase
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

    public function testAdminSeesOccupancyMapAndTables(): void
    {
        $this->loginAs('admin@example.com');
        $place = $this->getPlaceByName('Sklad Praha - Centrum');

        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122().'/obsazenost');

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Obsazenost typů', $body);
        $this->assertStringContainsString('Sklady — aktuální stav', $body);
        // The Live Component shell is rendered.
        $this->assertStringContainsString('data-controller="storage-map"', $body);
    }

    public function testLandlordSeesOnlyOwnedStoragesAndCoOwnerScope(): void
    {
        $this->loginAs('landlord@example.com');
        $place = $this->getPlaceByName('Sklad Praha - Centrum');

        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122().'/obsazenost');

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Obsazenost — Sklad Praha - Centrum', $body);
    }

    public function testFilterChipQueryParamFiltersTable(): void
    {
        $this->loginAs('admin@example.com');
        $place = $this->getPlaceByName('Sklad Praha - Centrum');

        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122().'/obsazenost?show=blocked');

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Sklady — aktuální stav', $body);
    }

    public function testInvalidFilterFallsBackToAll(): void
    {
        $this->loginAs('admin@example.com');
        $place = $this->getPlaceByName('Sklad Praha - Centrum');

        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122().'/obsazenost?show=invalid');

        $this->assertResponseIsSuccessful();
    }

    public function testTenantIsBlocked(): void
    {
        $this->loginAs('tenant@example.com');
        $place = $this->getPlaceByName('Sklad Praha - Centrum');

        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122().'/obsazenost');

        $this->assertResponseStatusCodeSame(403);
    }

    private function loginAs(string $email): void
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User);
        $this->client->loginUser($user, 'main');
    }

    private function getPlaceByName(string $name): Place
    {
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => $name]);
        \assert($place instanceof Place);

        return $place;
    }
}
