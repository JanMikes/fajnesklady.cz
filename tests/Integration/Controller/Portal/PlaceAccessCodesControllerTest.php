<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Portal;

use App\Entity\Place;
use App\Entity\PlaceStorageCodeUsage;
use App\Entity\Storage;
use App\Entity\User;
use App\Enum\StorageCodeUsageType;
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
        $this->persistUsage($place, '9876');

        $countBefore = $this->countUsage($place);

        $this->client->request('POST', '/portal/places/'.$place->id->toRfc4122().'/access-codes/reset', ['password' => 'password']);

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

    public function testResetRejectsWrongPassword(): void
    {
        $this->loginAsAdmin();
        $place = $this->getPrahaCentrum();

        $this->persistUsage($place, '9876');

        $countBefore = $this->countUsage($place);

        $this->client->request('POST', '/portal/places/'.$place->id->toRfc4122().'/access-codes/reset', ['password' => 'wrong-password']);

        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $countAfter = $this->countUsage($this->entityManager->find(Place::class, $place->id));

        $this->assertSame($countBefore, $countAfter, 'Nothing should be released when the password is wrong.');
    }

    public function testBulkGenerateRequiresCodesEnabled(): void
    {
        $this->loginAsAdmin();
        $place = $this->getBrno();

        $this->client->request('POST', '/portal/places/'.$place->id->toRfc4122().'/access-codes/bulk-generate');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testExcludeCreatesExcludedRows(): void
    {
        $this->loginAsAdmin();
        $place = $this->getPrahaCentrum();

        $this->client->request('POST', '/portal/places/'.$place->id->toRfc4122().'/access-codes/exclude', [
            'codes' => '0000, 1234',
            'note' => 'Servisní kód',
        ]);

        $this->assertResponseRedirects();
        $this->client->followRedirect();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Vyloučeno 2 kódů.', $body);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Place::class, $place->id);
        \assert($reloaded instanceof Place);
        foreach (['0000', '1234'] as $code) {
            $usage = $this->findUsage($reloaded, $code);
            $this->assertNotNull($usage, sprintf('Usage row for "%s" must exist.', $code));
            $this->assertSame(StorageCodeUsageType::EXCLUDED, $usage->type);
            $this->assertSame('Servisní kód', $usage->note);
        }
    }

    public function testExcludeFlipsExistingUsedCodeAndSurvivesReset(): void
    {
        $this->loginAsAdmin();
        $place = $this->getPrahaCentrum();

        // A USED row that is NOT an active lockCode — plain Reset would delete it.
        $this->persistUsage($place, '7654');

        $this->client->request('POST', '/portal/places/'.$place->id->toRfc4122().'/access-codes/exclude', [
            'codes' => '7654',
        ]);
        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Place::class, $place->id);
        \assert($reloaded instanceof Place);
        $usage = $this->findUsage($reloaded, '7654');
        $this->assertNotNull($usage);
        $this->assertSame(StorageCodeUsageType::EXCLUDED, $usage->type);

        $this->client->request('POST', '/portal/places/'.$place->id->toRfc4122().'/access-codes/reset', ['password' => 'password']);
        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Place::class, $place->id);
        \assert($reloaded instanceof Place);
        $this->assertNotNull(
            $this->findUsage($reloaded, '7654'),
            'The excluded code must survive "Resetovat použité kódy".',
        );
    }

    public function testExcludeInvalidCodePersistsNothing(): void
    {
        $this->loginAsAdmin();
        $place = $this->getPrahaCentrum();

        $countBefore = $this->countUsage($place);

        $this->client->request('POST', '/portal/places/'.$place->id->toRfc4122().'/access-codes/exclude', [
            'codes' => '12',
        ]);

        $this->assertResponseRedirects();
        $this->client->followRedirect();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Kód musí mít přesně 4 číslic.', $body);

        $this->entityManager->clear();
        $countAfter = $this->countUsage($this->entityManager->find(Place::class, $place->id));
        $this->assertSame($countBefore, $countAfter, 'Nothing may persist when a code fails validation.');
    }

    public function testExcludeActiveCodeWarnsButSucceeds(): void
    {
        $this->loginAsAdmin();
        $place = $this->getPrahaCentrum();

        // "0042" is the active lockCode on storage A1 (fixtures).
        $this->client->request('POST', '/portal/places/'.$place->id->toRfc4122().'/access-codes/exclude', [
            'codes' => '0042',
        ]);

        $this->assertResponseRedirects();
        $this->client->followRedirect();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('aktuálně přiřazené skladům', $body);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Place::class, $place->id);
        \assert($reloaded instanceof Place);
        $usage = $this->findUsage($reloaded, '0042');
        $this->assertNotNull($usage);
        $this->assertSame(StorageCodeUsageType::EXCLUDED, $usage->type);
    }

    public function testExcludeDeniedForLandlordWithoutManageCodes(): void
    {
        // landlord@ has no PlaceAccess to Ostrava and owns no storage there
        // (landlord2 co-owns storage Z1 at Praha Centrum, so Praha Centrum
        // grants MANAGE_CODES to both fixture landlords). The voter check runs
        // before the codes-enabled guard, so Ostrava still exercises the 403.
        $landlord = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'landlord@example.com']);
        \assert($landlord instanceof User);
        $this->client->loginUser($landlord, 'main');

        $place = $this->getOstrava();
        $this->client->request('POST', '/portal/places/'.$place->id->toRfc4122().'/access-codes/exclude', [
            'codes' => '0000',
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testUnexcludeRemovesExclusion(): void
    {
        $this->loginAsAdmin();
        $place = $this->getPrahaCentrum();

        // "9999" is the EXCLUDED fixture row (note "Servisní kód zámku").
        $usage = $this->findUsage($place, '9999');
        $this->assertNotNull($usage);
        $this->assertSame(StorageCodeUsageType::EXCLUDED, $usage->type);

        $this->client->request(
            'POST',
            '/portal/places/'.$place->id->toRfc4122().'/access-codes/exclusions/'.$usage->id->toRfc4122().'/remove',
        );

        $this->assertResponseRedirects();
        $this->client->followRedirect();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Vyloučení kódu bylo zrušeno.', $body);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Place::class, $place->id);
        \assert($reloaded instanceof Place);
        $this->assertNull($this->findUsage($reloaded, '9999'));
    }

    public function testUnexcludeDeniedForLandlordWithoutManageCodes(): void
    {
        $prahaCentrum = $this->getPrahaCentrum();
        $usage = $this->findUsage($prahaCentrum, '9999');
        $this->assertNotNull($usage);

        // See testExcludeDeniedForLandlordWithoutManageCodes for why Ostrava.
        $landlord = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'landlord@example.com']);
        \assert($landlord instanceof User);
        $this->client->loginUser($landlord, 'main');

        $place = $this->getOstrava();
        $this->client->request(
            'POST',
            '/portal/places/'.$place->id->toRfc4122().'/access-codes/exclusions/'.$usage->id->toRfc4122().'/remove',
        );

        $this->assertResponseStatusCodeSame(403);
    }

    public function testUnexcludeReturns404ForUsageOfAnotherPlace(): void
    {
        $this->loginAsAdmin();
        $prahaCentrum = $this->getPrahaCentrum();
        $usage = $this->findUsage($prahaCentrum, '9999');
        $this->assertNotNull($usage);

        // Enable codes on a second place so the request reaches the
        // place-ownership check instead of the codes-enabled 400 guard.
        $prahaJih = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => 'Sklad Praha - Jiznimesto']);
        \assert($prahaJih instanceof Place);
        $prahaJih->updateStorageCodeConfig(true, 4, 0, 9999, new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->client->request(
            'POST',
            '/portal/places/'.$prahaJih->id->toRfc4122().'/access-codes/exclusions/'.$usage->id->toRfc4122().'/remove',
        );

        $this->assertResponseStatusCodeSame(404);
    }

    public function testHistoryTableRendersBothBadges(): void
    {
        $this->loginAsAdmin();
        $place = $this->getPrahaCentrum();

        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122().'/access-codes');

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Historie kódů', $body);
        $this->assertStringContainsString('Vyloučené kódy', $body);
        $this->assertStringContainsString('Použitý', $body);
        $this->assertStringContainsString('Vyloučený', $body);
        $this->assertStringContainsString('Servisní kód zámku', $body);
        $this->assertStringContainsString('Zrušit vyloučení', $body);
    }

    private function loginAsAdmin(): void
    {
        $admin = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'admin@example.com']);
        \assert($admin instanceof User);
        $this->client->loginUser($admin, 'main');
    }

    private function persistUsage(Place $place, string $code): void
    {
        $this->entityManager->persist(new PlaceStorageCodeUsage(
            id: \Symfony\Component\Uid\Uuid::v7(),
            place: $place,
            code: $code,
            type: StorageCodeUsageType::USED,
            note: null,
            usedAt: new \DateTimeImmutable(),
        ));
        $this->entityManager->flush();
    }

    private function findUsage(Place $place, string $code): ?PlaceStorageCodeUsage
    {
        return $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(PlaceStorageCodeUsage::class, 'u')
            ->where('u.place = :place')
            ->andWhere('u.code = :code')
            ->setParameter('place', $place)
            ->setParameter('code', $code)
            ->getQuery()
            ->getOneOrNullResult();
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

    private function getOstrava(): Place
    {
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => 'Sklad Ostrava']);
        \assert($place instanceof Place);

        return $place;
    }
}
