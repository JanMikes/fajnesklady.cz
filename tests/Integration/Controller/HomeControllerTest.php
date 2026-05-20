<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Homepage map section — spec 048.
 *
 * Asserts the redesigned `#pobocky` markup: max-w container, compact two-line cards,
 * binary availability badges, geolocation button, and the absence of the legacy
 * verbose copy (`✓ N volných`, `Nedostupné`, address line, etc.).
 */
final class HomeControllerTest extends WebTestCase
{
    public function testHomepageRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
    }

    public function testSectionIsWrappedInContainer(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $this->assertCount(
            1,
            $crawler->filter('#pobocky .max-w-6xl'),
            'Sekce #pobocky musí být obalená v max-w-6xl containeru pro vizuální rytmus se sousedními sekcemi.',
        );
    }

    public function testCardsShowBinaryAvailabilityBadge(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $html = $crawler->filter('#pobocky')->html();

        $this->assertStringContainsString('K dispozici', $html, 'Compact card musí ukazovat zelený badge "K dispozici" pro pobočky s volnými skladovacími jednotkami.');
        $this->assertStringNotContainsString('volných', $html, 'Číselný count "N volných" už nesmí být v UI — pouze binární badge.');
        $this->assertStringNotContainsString('Nedostupné', $html, 'Disabled "Nedostupné" chip byl odstraněn.');
    }

    public function testGeolocationButtonIsPresent(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $button = $crawler->filter('#pobocky [data-map-target="geoButton"]');

        $this->assertCount(1, $button, 'Tlačítko "Najít nejbližší pobočku" musí být v sekci.');
        $this->assertStringContainsString('Najít nejbližší pobočku', $button->text());
    }

    public function testMobileBottomSheetAndModalSlotsArePresent(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $this->assertCount(1, $crawler->filter('#pobocky [data-map-target="bottomSheetPill"]'));
        $this->assertCount(1, $crawler->filter('#pobocky [data-map-target="bottomSheet"]'));
        $this->assertCount(1, $crawler->filter('#pobocky [data-map-target="modal"]'));
        $this->assertCount(1, $crawler->filter('#pobocky [data-map-target="closestChip"]'));
    }

    public function testLegacyCardCopyIsGone(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $html = $crawler->filter('#pobocky')->html();

        // The old "Zobrazit detail →" CTA button on every card is replaced by a covering <a>.
        $this->assertStringNotContainsString('btn btn-primary btn-sm w-full', $html);
        // The old per-card storage-type pill row is gone — the cards just show binary availability.
        $this->assertStringNotContainsString('bg-gray-100 text-gray-600', $html);
    }

    public function testAvailableCardsPrecedeSoldOutCards(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $cards = $crawler->filter('#pobocky [data-map-target="placeCard"]');
        if ($cards->count() < 2) {
            $this->markTestSkipped('Méně než 2 pobočky ve fixtures — nelze ověřit pořadí.');
        }

        $seenSoldOut = false;
        foreach ($cards as $node) {
            $html = $node->ownerDocument?->saveHTML($node) ?? '';
            $isSoldOut = str_contains($html, 'badge-sold-out');
            $isAvailable = str_contains($html, 'badge-available');
            if ($isSoldOut) {
                $seenSoldOut = true;
                continue;
            }
            $this->assertFalse(
                $seenSoldOut && $isAvailable,
                'Pobočka "K dispozici" se objevila po pobočce "Obsazeno" — řazení podle availabilityRatio DESC je rozbité.',
            );
        }
    }

    public function testPlacesJsonDoesNotLeakAvailableCount(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $placesJson = $crawler->filter('#pobocky[data-map-places-value]')->attr('data-map-places-value');
        $this->assertNotNull($placesJson);
        $this->assertNotEmpty($placesJson);

        // The `availableCount` field was dropped — UI is binary; counts stay server-side.
        $this->assertStringNotContainsString('availableCount', $placesJson);
        // The new `isAvailable` per-type flag is what the popover reads.
        $this->assertStringContainsString('isAvailable', $placesJson);
    }
}
