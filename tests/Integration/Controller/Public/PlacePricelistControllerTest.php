<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Public;

use App\Entity\Place;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class PlacePricelistControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testPageLoadsForActivePlace(): void
    {
        $place = $this->findPlaceByName('Sklad Praha - Centrum');

        $this->client->request('GET', '/pobocka/'.$place->id->toRfc4122().'/cenik');

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Ceník skladů', $body);
        $this->assertStringContainsString($place->name, $body);
        // Praha Centrum has SMALL boxes with AVAILABLE storages (A1, A2, A3, A5)
        // and MEDIUM/LARGE that are fully OCCUPIED/RESERVED — so both status pills
        // must appear at least once on the rendered page.
        $this->assertStringContainsString('Dostupné', $body);
        $this->assertStringContainsString('Obsazeno', $body);
    }

    public function testOrderCtaPointsToOrderCreateRoute(): void
    {
        $place = $this->findPlaceByName('Sklad Praha - Centrum');

        $this->client->request('GET', '/pobocka/'.$place->id->toRfc4122().'/cenik');

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertMatchesRegularExpression(
            '~/objednavka/'.$place->id->toRfc4122().'/[0-9a-f-]{36}~',
            $body,
            'Pricelist row must contain an Objednat link to public_order_create.',
        );
    }

    public function testReturns404ForUnknownUuid(): void
    {
        $this->client->request('GET', '/pobocka/'.Uuid::v7()->toRfc4122().'/cenik');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testReturns404ForMalformedId(): void
    {
        $this->client->request('GET', '/pobocka/not-a-uuid/cenik');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testReturns404ForInactivePlace(): void
    {
        // DAMA DoctrineTestBundle rolls back this mutation at the end of the test.
        $place = $this->findPlaceByName('Sklad Praha - Centrum');
        $place->deactivate(new \DateTimeImmutable('2025-06-15 12:00:00'));
        $this->entityManager->flush();

        $this->client->request('GET', '/pobocka/'.$place->id->toRfc4122().'/cenik');

        $this->assertResponseStatusCodeSame(404);
    }

    private function findPlaceByName(string $name): Place
    {
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => $name]);
        \assert($place instanceof Place, sprintf('Fixture place "%s" not found', $name));

        return $place;
    }
}
