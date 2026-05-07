<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Portal;

use App\Entity\Place;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PlaceDetailControllerTest extends WebTestCase
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

    public function testAdminSeesDashboardSectionsAndHub(): void
    {
        $this->loginAs('admin@example.com');
        $place = $this->getPlaceByName('Sklad Praha - Centrum');

        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Obsazenost', $body);
        $this->assertStringContainsString('Tržby minulý měsíc', $body);
        $this->assertStringContainsString('Správa místa', $body);
        $this->assertStringContainsString('Sklady', $body);
        $this->assertStringContainsString('Editor mapy', $body);
    }

    public function testAdminSeesPoSplatnostiBannerWhenDebtorAtPlace(): void
    {
        // Fixture contract REF_CONTRACT_UNLIMITED is overdue and lives at storage C1 in Praha Centrum.
        $this->loginAs('admin@example.com');
        $place = $this->getPlaceByName('Sklad Praha - Centrum');

        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Po splatnosti', $body);
    }

    public function testAdminSeesSetupHealthAlertsForFreshlyCreatedPlace(): void
    {
        $this->loginAs('admin@example.com');

        // Plzen has no map, no operating rules and no storage types in fixtures.
        $place = $this->getPlaceByName('Sklad Plzen');

        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Chybí nahrát provozní řád', $body);
        $this->assertStringContainsString('Mapa není nahrána', $body);
        $this->assertStringContainsString('Žádné typy skladů', $body);
    }

    public function testLandlordSeesCoOwnerDisclaimerWhenAnotherOwnerHasStorageHere(): void
    {
        // Storage Z1 at Praha Centrum is owned by landlord2; landlord owns the rest.
        $this->loginAs('landlord@example.com');
        $place = $this->getPlaceByName('Sklad Praha - Centrum');

        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Vidíte pouze své sklady', $body);
        // Setup-health alerts must not show for landlords.
        $this->assertStringNotContainsString('Chybí nahrát provozní řád', $body);
    }

    public function testLandlordDoesNotSeeCoOwnerDisclaimerOnSoloPlace(): void
    {
        $this->loginAs('landlord@example.com');
        $place = $this->getPlaceByName('Sklad Praha - Jiznimesto');

        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('Vidíte pouze své sklady', $body);
    }

    public function testTenantIsBlockedByRoleLandlordGate(): void
    {
        $this->loginAs('tenant@example.com');
        $place = $this->getPlaceByName('Sklad Praha - Centrum');

        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122());

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
