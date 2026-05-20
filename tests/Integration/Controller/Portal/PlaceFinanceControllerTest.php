<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Portal;

use App\Entity\Place;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PlaceFinanceControllerTest extends WebTestCase
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

    public function testAdminSeesKpiTilesChartAndMonthlyTable(): void
    {
        $this->loginAs('admin@example.com');
        $place = $this->getPlaceByName('Sklad Praha - Centrum');

        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122().'/finance');

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Tržby tento měsíc', $body);
        $this->assertStringContainsString('Tržby minulý měsíc', $body);
        $this->assertStringContainsString('Tržby YTD', $body);
        $this->assertStringContainsString('Očekávané MRR', $body);
        $this->assertStringContainsString('Měsíční tržby', $body);
    }

    public function testLandlordSeesScopedFinance(): void
    {
        $this->loginAs('landlord@example.com');
        $place = $this->getPlaceByName('Sklad Praha - Centrum');

        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122().'/finance');

        $this->assertResponseIsSuccessful();
    }

    public function testTenantIsBlocked(): void
    {
        $this->loginAs('tenant@example.com');
        $place = $this->getPlaceByName('Sklad Praha - Centrum');

        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122().'/finance');

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
