<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Baseline accessibility guards (WCAG 2.1 A/AA).
 *
 * These are the defects an automated pass actually catches; they are also the
 * ones that silently regress when a template is copy-pasted. Scope is
 * deliberately narrow — real conformance needs manual testing — but a
 * regression here is always a regression.
 *
 * Legal context: zákon č. 424/2023 Sb. (EAA) binds e-commerce services from
 * 28. 6. 2025 unless the provider is a mikropodnik (§ 2 odst. 3 písm. a).
 * See .claude/COMPLIANCE.md.
 */
final class AccessibilityTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function pageProvider(): iterable
    {
        yield 'homepage' => ['/'];
        yield 'login' => ['/login'];
        yield 'privacy policy' => ['/ochrana-osobnich-udaju'];
        yield 'terms and conditions' => ['/obchodni-podminky'];
        yield 'consumer notice' => ['/pouceni-spotrebitele'];
    }

    #[DataProvider('pageProvider')]
    public function testPageDeclaresCzechLanguage(string $uri): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', $uri);

        // WCAG 3.1.1 (A) — screen readers pick the voice from this.
        self::assertSame('cs', $crawler->filter('html')->attr('lang'), sprintf('%s musí mít lang="cs".', $uri));
    }

    #[DataProvider('pageProvider')]
    public function testPageHasExactlyOneMainHeading(string $uri): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', $uri);

        self::assertCount(1, $crawler->filter('h1'), sprintf('%s musí mít právě jeden <h1>.', $uri));
    }

    #[DataProvider('pageProvider')]
    public function testPageOffersASkipLink(string $uri): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', $uri);

        // WCAG 2.4.1 (A) — Bypass Blocks.
        $skip = $crawler->filter('a.skip-link');
        self::assertCount(1, $skip, sprintf('%s musí mít odkaz pro přeskočení navigace.', $uri));
        self::assertSame('#hlavni-obsah', $skip->attr('href'));
        self::assertCount(1, $crawler->filter('#hlavni-obsah'), 'Cíl odkazu pro přeskočení musí na stránce existovat.');
    }

    #[DataProvider('pageProvider')]
    public function testEveryImageHasAnAltAttribute(string $uri): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', $uri);

        // WCAG 1.1.1 (A). alt="" is valid and means "decorative" — only a
        // missing attribute is a failure.
        $missing = $crawler->filter('img')->reduce(
            static fn ($node): bool => null === $node->attr('alt'),
        );

        self::assertCount(0, $missing, sprintf('Na %s má %d <img> chybějící atribut alt.', $uri, $missing->count()));
    }

    public function testLoginFieldsAreProgrammaticallyLabelled(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        // WCAG 1.3.1 / 4.1.2 — a <label> with no `for` is invisible to assistive
        // tech even though it looks correct on screen.
        foreach (['login-username' => '_username', 'login-password' => '_password'] as $id => $name) {
            self::assertCount(1, $crawler->filter(sprintf('label[for="%s"]', $id)), sprintf('Pole %s musí mít svázaný <label>.', $name));
            self::assertCount(1, $crawler->filter(sprintf('input#%s[name="%s"]', $id, $name)), sprintf('Pole %s musí mít odpovídající id.', $name));
        }
    }
}
