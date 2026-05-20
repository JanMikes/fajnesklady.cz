<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Portal;

use App\Entity\Place;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PlaceContractsControllerTest extends WebTestCase
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

    public function testAdminSeesAllFourSectionsByDefault(): void
    {
        $this->loginAs('admin@example.com');
        $place = $this->getPlaceByName('Sklad Praha - Centrum');

        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122().'/smlouvy');

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        // The chip strip is always present.
        $this->assertStringContainsString('Vše', $body);
        $this->assertStringContainsString('Aktivní', $body);
        $this->assertStringContainsString('Brzy končící', $body);
        $this->assertStringContainsString('Co se chystá', $body);
        $this->assertStringContainsString('Nedávné', $body);
    }

    public function testActiveChipHidesOtherSections(): void
    {
        $this->loginAs('admin@example.com');
        $place = $this->getPlaceByName('Sklad Praha - Centrum');

        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122().'/smlouvy?show=active');

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        // The headings of non-selected sections are absent.
        $this->assertStringNotContainsString('Brzy končící smlouvy (≤', $body);
        $this->assertStringNotContainsString('Posledních 20 objednávek', $body);
    }

    public function testInvalidFilterFallsBackToAll(): void
    {
        $this->loginAs('admin@example.com');
        $place = $this->getPlaceByName('Sklad Praha - Centrum');

        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122().'/smlouvy?show=nonsense');

        $this->assertResponseIsSuccessful();
    }

    public function testTenantIsBlocked(): void
    {
        $this->loginAs('tenant@example.com');
        $place = $this->getPlaceByName('Sklad Praha - Centrum');

        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122().'/smlouvy');

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
