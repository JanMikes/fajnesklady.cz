<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Portal;

use App\Entity\Place;
use App\Entity\PlaceStorageCodeUsage;
use App\Entity\Storage;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PlaceAccessCodesControllerTest extends WebTestCase
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

    public function testRequiresPlaceEditPermission(): void
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'user@example.com']);
        \assert($user instanceof User);
        $this->client->loginUser($user, 'main');

        $place = $this->getPrahaCentrum();
        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122().'/access-codes');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminCanViewConfigPage(): void
    {
        $this->loginAsAdmin();
        $place = $this->getPrahaCentrum();

        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122().'/access-codes');

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Přístupové kódy', $body);
    }

    public function testConfigSaveRoundTrips(): void
    {
        $this->loginAsAdmin();
        $place = $this->getBrno();

        $this->client->request('POST', '/portal/places/'.$place->id->toRfc4122().'/access-codes', [
            'place_storage_code_config_form' => [
                'enabled' => '1',
                'digits' => '5',
                'from' => '10000',
                'to' => '99999',
            ],
        ]);

        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Place::class, $place->id);
        \assert($reloaded instanceof Place);
        $this->assertTrue($reloaded->storageCodesEnabled);
        $this->assertSame(5, $reloaded->storageCodeDigits);
        $this->assertSame(10000, $reloaded->storageCodeFrom);
        $this->assertSame(99999, $reloaded->storageCodeTo);
    }

    public function testBulkGenerateFillsEmptyStorages(): void
    {
        $this->loginAsAdmin();
        $place = $this->getPrahaCentrum();

        // Find at least one storage with a NULL lockCode to assert bulk-fill happened.
        $emptyBefore = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Storage::class, 's')
            ->where('s.place = :place')
            ->andWhere('s.deletedAt IS NULL')
            ->andWhere('s.lockCode IS NULL')
            ->setParameter('place', $place)
            ->getQuery()
            ->getSingleScalarResult();
        $this->assertGreaterThan(0, $emptyBefore, 'Test relies on Praha Centrum having storages without codes.');

        $this->client->request('POST', '/portal/places/'.$place->id->toRfc4122().'/access-codes/bulk-generate');

        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $emptyAfter = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Storage::class, 's')
            ->where('s.place = :place')
            ->andWhere('s.deletedAt IS NULL')
            ->andWhere('s.lockCode IS NULL')
            ->setParameter('place', $place)
            ->getQuery()
            ->getSingleScalarResult();
        $this->assertSame(0, $emptyAfter);
    }

    public function testResetDeletesUsageRowsNotCurrentlyAssigned(): void
    {
        $this->loginAsAdmin();
        $place = $this->getPrahaCentrum();

        // Add an extra historical row that is NOT currently a lockCode on any storage.
        $this->entityManager->persist(new PlaceStorageCodeUsage(
            id: \Symfony\Component\Uid\Uuid::v7(),
            place: $place,
            code: '9876',
            usedAt: new \DateTimeImmutable(),
        ));
        $this->entityManager->flush();

        $countBefore = $this->countUsage($place);

        $this->client->request('POST', '/portal/places/'.$place->id->toRfc4122().'/access-codes/reset');

        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $countAfter = $this->countUsage($this->entityManager->find(Place::class, $place->id));

        $this->assertLessThan($countBefore, $countAfter, 'At least the stale "9876" should have been released.');

        // The currently-assigned codes (0042 on A1, 0577 on C1) must survive.
        $survivingCodes = $this->entityManager->getRepository(PlaceStorageCodeUsage::class)
            ->findBy(['place' => $this->entityManager->find(Place::class, $place->id)]);
        $codes = array_map(static fn (PlaceStorageCodeUsage $u) => $u->code, $survivingCodes);
        $this->assertContains('0042', $codes);
        $this->assertContains('0577', $codes);
        $this->assertNotContains('9876', $codes);
    }

    public function testBulkGenerateRequiresCodesEnabled(): void
    {
        $this->loginAsAdmin();
        $place = $this->getBrno();

        $this->client->request('POST', '/portal/places/'.$place->id->toRfc4122().'/access-codes/bulk-generate');

        $this->assertResponseStatusCodeSame(400);
    }

    private function loginAsAdmin(): void
    {
        $admin = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'admin@example.com']);
        \assert($admin instanceof User);
        $this->client->loginUser($admin, 'main');
    }

    private function countUsage(Place $place): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(PlaceStorageCodeUsage::class, 'u')
            ->where('u.place = :place')
            ->setParameter('place', $place)
            ->getQuery()
            ->getSingleScalarResult();
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
