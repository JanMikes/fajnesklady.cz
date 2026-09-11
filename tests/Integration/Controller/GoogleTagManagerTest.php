<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Google Tag Manager + cookie consent wiring.
 *
 * Guards the two things that are easy to break silently and expensive to get
 * wrong legally:
 *   1. the GTM snippets are on every page, in the required positions;
 *   2. Consent Mode v2 defaults to `denied` and is pushed BEFORE gtm.js loads,
 *      so no Google tag can fire before the visitor has opted in
 *      (ZEK § 89 odst. 3 — opt-in since 1. 1. 2022).
 *
 * See .claude/COMPLIANCE.md "Cookies & tracking".
 */
final class GoogleTagManagerTest extends WebTestCase
{
    private const CONTAINER_ID = 'GTM-N2WJXDPT';

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicPageProvider(): iterable
    {
        yield 'homepage' => ['/'];
        yield 'privacy policy' => ['/ochrana-osobnich-udaju'];
        yield 'terms and conditions' => ['/obchodni-podminky'];
        yield 'login' => ['/login'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('publicPageProvider')]
    public function testGtmSnippetsArePresentOnEveryPage(string $uri): void
    {
        $client = static::createClient();
        $client->request('GET', $uri);

        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString(
            "https://www.googletagmanager.com/gtm.js?id='+i+dl",
            $html,
            sprintf('GTM head snippet chybí na %s.', $uri),
        );
        self::assertStringContainsString(
            'https://www.googletagmanager.com/ns.html?id='.self::CONTAINER_ID,
            $html,
            sprintf('GTM noscript iframe chybí na %s.', $uri),
        );
    }

    public function testHeadSnippetSitsAboveTheTitleTag(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $html = (string) $client->getResponse()->getContent();

        $gtmPosition = strpos($html, 'googletagmanager.com/gtm.js');
        $titlePosition = strpos($html, '<title>');

        self::assertIsInt($gtmPosition);
        self::assertIsInt($titlePosition);
        self::assertLessThan(
            $titlePosition,
            $gtmPosition,
            'GTM musí být co nejvýše v <head> — klient si vyžádal umístění nad zbytkem hlavičky.',
        );
    }

    public function testNoscriptIframeIsTheFirstThingInsideBody(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $html = (string) $client->getResponse()->getContent();

        $bodyTagEnd = strpos($html, '>', (int) strpos($html, '<body'));
        $noscriptPosition = strpos($html, '<noscript>');

        self::assertIsInt($bodyTagEnd);
        self::assertIsInt($noscriptPosition);

        $betweenBodyAndNoscript = substr(
            $html,
            $bodyTagEnd + 1,
            $noscriptPosition - $bodyTagEnd - 1,
        );

        self::assertDoesNotMatchRegularExpression(
            '/<[a-z]/i',
            $betweenBodyAndNoscript,
            'Mezi otevíracím <body> a GTM <noscript> nesmí být žádný jiný element.',
        );
        self::assertStringContainsString(
            'googletagmanager.com/ns.html',
            substr($html, $noscriptPosition, 200),
            'První <noscript> v body musí být ten od GTM.',
        );
    }

    public function testConsentDefaultsToDeniedBeforeTheContainerLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $html = (string) $client->getResponse()->getContent();

        $consentPosition = strpos($html, "gtag('consent', 'default'");
        $containerPosition = strpos($html, 'googletagmanager.com/gtm.js');

        self::assertIsInt($consentPosition, 'Consent Mode v2 default blok chybí.');
        self::assertIsInt($containerPosition);
        self::assertLessThan(
            $containerPosition,
            $consentPosition,
            'Consent default musí být v dataLayeru dřív, než se načte gtm.js — jinak první hit odejde bez consent signálu.',
        );

        // All four Consent Mode v2 signals must start denied.
        foreach (['ad_storage', 'ad_user_data', 'ad_personalization', 'analytics_storage'] as $signal) {
            self::assertMatchesRegularExpression(
                '/'.$signal.":\s*'denied'/",
                $html,
                sprintf('Signál %s musí být defaultně "denied".', $signal),
            );
        }
    }

    public function testCookieBannerOffersRejectInTheFirstLayer(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $banner = $crawler->filter('[aria-label="Nastavení souborů cookies"]');
        self::assertCount(1, $banner, 'Cookie lišta musí být na stránce.');

        $html = $banner->html();

        // ÚOOÚ: "odmítnout" must be reachable in the first layer and must not be
        // visually weaker than "accept". Both are plain `.btn` at the same size.
        self::assertStringContainsString('Odmítnout vše', $html);
        self::assertStringContainsString('Přijmout vše', $html);
        self::assertStringNotContainsString('btn-xs', $html, 'Odmítnutí ani přijetí nesmí být zmenšené oproti druhé volbě.');
    }

    public function testNoNonEssentialCategoryIsPreChecked(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        foreach (['analytics', 'preferences', 'ads'] as $category) {
            $checkbox = $crawler->filter('#cookie-consent-'.$category);

            self::assertCount(1, $checkbox, sprintf('Kategorie "%s" chybí v detailní vrstvě lišty.', $category));
            self::assertNull(
                $checkbox->attr('checked'),
                sprintf('Kategorie "%s" nesmí být předzaškrtnutá — předzaškrtnutí je porušení zákona.', $category),
            );
        }
    }

    public function testConsentCanBeWithdrawnFromTheFooter(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertStringContainsString(
            'Nastavení cookies',
            $crawler->filter('footer')->html(),
            'Odvolání souhlasu musí být stejně snadné jako jeho udělení — odkaz patří do patičky.',
        );
    }
}
