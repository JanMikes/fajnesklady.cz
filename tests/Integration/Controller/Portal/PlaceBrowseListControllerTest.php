<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Portal;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * /portal/pobocky — the browse page shares GetPlacesOverview and the
 * PlacesMapPayload shape with the homepage (constant query count, `isAvailable`
 * flags the map JS reads), but keeps its own alphabetical table order.
 * Role/redirect access checks live in ControllerAccessTest.
 */
final class PlaceBrowseListControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $user = static::getContainer()->get('doctrine')->getManager()
            ->getRepository(User::class)->findOneBy(['email' => 'user@example.com']);
        \assert($user instanceof User);
        $this->client->loginUser($user, 'main');
    }

    public function testTableListsPlacesAlphabetically(): void
    {
        $crawler = $this->client->request('GET', '/portal/pobocky');

        $this->assertResponseIsSuccessful();

        $names = $crawler->filter('table tbody tr td:first-child span.font-semibold')
            ->each(static fn ($node): string => trim($node->text()));

        self::assertNotEmpty($names, 'Tabulka poboček musí obsahovat řádky.');

        $sorted = $names;
        natcasesort($sorted);
        self::assertSame(array_values($sorted), $names, 'Pobočky v tabulce musí být řazené abecedně, ne podle dostupnosti.');
        self::assertContains('Sklad Praha - Centrum', $names);
    }

    public function testTableShowsStorageTypeNames(): void
    {
        $crawler = $this->client->request('GET', '/portal/pobocky');

        $html = $crawler->filter('table')->html();

        self::assertStringContainsString('Maly box', $html);
        self::assertStringContainsString('Stredni box', $html);
        self::assertStringNotContainsString('Admin box', $html, 'Admin-only typy nesmí být na browse stránce.');
    }

    public function testPlacesJsonMatchesMapJsContract(): void
    {
        $crawler = $this->client->request('GET', '/portal/pobocky');

        $placesJson = $crawler->filter('[data-map-places-value]')->attr('data-map-places-value');
        self::assertNotNull($placesJson);

        /** @var list<array<string, mixed>> $places */
        $places = json_decode($placesJson, true, 512, \JSON_THROW_ON_ERROR);
        self::assertNotEmpty($places);

        // The map JS reads `isAvailable` on places and types; the legacy
        // `availableCount` payload rendered every place as "Obsazeno".
        self::assertStringNotContainsString('availableCount', $placesJson);
        foreach ($places as $place) {
            self::assertArrayHasKey('isAvailable', $place);
            self::assertArrayHasKey('url', $place);
            self::assertStringContainsString('/portal/pobocka/', (string) $place['url'], 'Detail pobočky musí vést do portálu, ne na veřejný web.');
            foreach ($place['storageTypes'] as $type) {
                self::assertArrayHasKey('isAvailable', $type);
                self::assertArrayHasKey('orderUrl', $type);
            }
        }

        $availableFlags = array_column($places, 'isAvailable');
        self::assertContains(true, $availableFlags, 'Fixtures obsahují dostupné pobočky — mapa je nesmí všechny ukazovat jako obsazené.');
    }

    public function testQueryCountIsBoundedRegardlessOfDataSize(): void
    {
        $this->client->enableProfiler();
        $this->client->request('GET', '/portal/pobocky');

        $this->assertResponseIsSuccessful();

        $profile = $this->client->getProfile();
        self::assertNotFalse($profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(\Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector::class, $collector);

        // GetPlacesOverviewQuery runs a constant 6 queries; the authenticated
        // request adds a few (user refresh, session). The old per-type
        // countAvailableStorages() loop fired 70+ on fixture data.
        self::assertLessThan(14, $collector->getQueryCount(), sprintf('Browse stránka musí běžet na konstantním počtu dotazů, naměřeno %d — pravděpodobně regrese N+1.', $collector->getQueryCount()));
    }
}
