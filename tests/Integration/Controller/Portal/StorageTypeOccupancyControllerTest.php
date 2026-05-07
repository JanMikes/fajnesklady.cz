<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Portal;

use App\Entity\Place;
use App\Entity\StorageType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class StorageTypeOccupancyControllerTest extends WebTestCase
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

    public function testTenantIsRejected(): void
    {
        $this->loginAs('tenant@example.com');
        [$place, $type] = $this->getPlaceAndAnyType('Sklad Praha - Centrum');

        $this->client->request('GET', $this->urlFor($place, $type));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testLandlordSeesPlanningPageWithKpiAndTimeline(): void
    {
        $this->loginAs('landlord@example.com');
        [$place, $type] = $this->getPlaceAndTypeByName('Sklad Praha - Centrum', 'Maly box');

        $this->client->request('GET', $this->urlFor($place, $type));

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Plánování obsazenosti', $body);
        $this->assertStringContainsString($type->name, $body);
        $this->assertStringContainsString('Celkem', $body);
        $this->assertStringContainsString('Obsazeno', $body);
        $this->assertStringContainsString('Volných', $body);
        $this->assertStringContainsString('storage-type-timeline', $body);
    }

    public function testLandlordSeesUkoncujeSeWarningForTerminatingContract(): void
    {
        // Storage E1 (Praha Jih, Stredni box) carries REF_CONTRACT_TERMINATING.
        $this->loginAs('landlord@example.com');
        [$place, $type] = $this->getPlaceAndTypeByName('Sklad Praha - Jiznimesto', 'Stredni box');

        $this->client->request('GET', $this->urlFor($place, $type).'?show=occupied');

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Ukončuje se', $body);
    }

    public function testLandlord2SeesEmptyPlanningWhenNoOwnedStoragesOfType(): void
    {
        // landlord2 owns Z1 (Small) at Praha Centrum but no storages of type "Malý box"
        // owned exclusively by them — wait, Z1 IS the Small Centrum type. landlord2
        // sees only Z1 here. Use a type they own none of: "Velký box" at Centrum.
        $this->loginAs('landlord2@example.com');
        [$place, $type] = $this->getPlaceAndTypeByName('Sklad Praha - Centrum', 'Velky box');

        $this->client->request('GET', $this->urlFor($place, $type));

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Žádné sklady tohoto typu', $body);
    }

    public function testAdminSeesAllStoragesOfTypeRegardlessOfOwner(): void
    {
        // Admin sees both landlord's smalls (A1-A5) and landlord2's Z1.
        $this->loginAs('admin@example.com');
        [$place, $type] = $this->getPlaceAndTypeByName('Sklad Praha - Centrum', 'Maly box');

        $this->client->request('GET', $this->urlFor($place, $type));

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        // Z1 is owned by landlord2; admin sees it.
        $this->assertStringContainsString('Z1', $body);
    }

    private function urlFor(Place $place, StorageType $type): string
    {
        return sprintf('/portal/places/%s/storage-types/%s/obsazenost', $place->id->toRfc4122(), $type->id->toRfc4122());
    }

    private function loginAs(string $email): void
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User);
        $this->client->loginUser($user, 'main');
    }

    /**
     * @return array{0: Place, 1: StorageType}
     */
    private function getPlaceAndAnyType(string $placeName): array
    {
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => $placeName]);
        \assert($place instanceof Place);

        $type = $this->entityManager->createQueryBuilder()
            ->select('st')
            ->from(StorageType::class, 'st')
            ->where('st.place = :place')
            ->setParameter('place', $place)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($type instanceof StorageType);

        return [$place, $type];
    }

    /**
     * @return array{0: Place, 1: StorageType}
     */
    private function getPlaceAndTypeByName(string $placeName, string $typeName): array
    {
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => $placeName]);
        \assert($place instanceof Place);

        $type = $this->entityManager->createQueryBuilder()
            ->select('st')
            ->from(StorageType::class, 'st')
            ->where('st.place = :place')
            ->andWhere('st.name = :name')
            ->setParameter('place', $place)
            ->setParameter('name', $typeName)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($type instanceof StorageType);

        return [$place, $type];
    }
}
