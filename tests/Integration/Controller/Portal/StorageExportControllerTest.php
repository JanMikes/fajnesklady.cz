<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Portal;

use App\DataFixtures\UserFixtures;
use App\Entity\Place;
use App\Tests\Integration\Controller\ExcelExportTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class StorageExportControllerTest extends WebTestCase
{
    use ExcelExportTestTrait;

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

    public function testAnonymousIsRedirected(): void
    {
        $place = $this->findPlaceByName('Sklad Praha - Centrum');
        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122().'/storages/export');

        $this->assertResponseRedirects('/login');
    }

    public function testRegularUserGetsForbidden(): void
    {
        $place = $this->findPlaceByName('Sklad Praha - Centrum');
        $this->client->loginUser($this->findUserByEmail($this->entityManager, UserFixtures::USER_EMAIL), 'main');
        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122().'/storages/export');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testLandlordExportRestrictsToOwnedStorages(): void
    {
        $landlord = $this->findUserByEmail($this->entityManager, UserFixtures::LANDLORD_EMAIL);
        $place = $this->findPlaceByName('Sklad Praha - Centrum');

        $this->client->loginUser($landlord, 'main');
        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122().'/storages/export');

        $body = $this->assertXlsxResponse($this->client);
        $rows = $this->readXlsxRows($body);

        self::assertSame('Číslo', $rows[0][0]);
        // Export header should NOT carry "Vlastník" for landlord scope.
        $headerCount = count($rows[0]);
        self::assertSame(9, $headerCount, 'Landlord export must omit the admin-only Vlastník column.');
    }

    public function testLandlordExportingOtherLandlordsPlaceGetsEmptyRows(): void
    {
        // PlaceVoter::VIEW lets every landlord browse every place; the
        // owner-scoping is enforced inside StorageRepository::findFiltered($user,...),
        // so the export streams an empty data set rather than 403'ing.
        $landlord = $this->findUserByEmail($this->entityManager, UserFixtures::LANDLORD_EMAIL);
        $ostrava = $this->findPlaceByName('Sklad Ostrava');

        $this->client->loginUser($landlord, 'main');
        $this->client->request('GET', '/portal/places/'.$ostrava->id->toRfc4122().'/storages/export');

        $body = $this->assertXlsxResponse($this->client);
        $rows = $this->readXlsxRows($body);

        // Only the header row — no landlord2-owned storages should leak.
        self::assertCount(1, $rows);
    }

    public function testAdminExportIncludesOwnerColumn(): void
    {
        $admin = $this->findUserByEmail($this->entityManager, UserFixtures::ADMIN_EMAIL);
        $place = $this->findPlaceByName('Sklad Praha - Centrum');

        $this->client->loginUser($admin, 'main');
        $this->client->request('GET', '/portal/places/'.$place->id->toRfc4122().'/storages/export');

        $body = $this->assertXlsxResponse($this->client);
        $rows = $this->readXlsxRows($body);

        self::assertSame('Vlastník', $rows[0][9]);
    }

    private function findPlaceByName(string $name): Place
    {
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => $name]);
        \assert($place instanceof Place, sprintf('Place "%s" not found in fixtures', $name));

        return $place;
    }
}
