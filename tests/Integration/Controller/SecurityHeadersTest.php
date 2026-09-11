<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Browser-facing security headers (App\Event\SecurityHeadersSubscriber).
 *
 * The bug these guard against was silent: the headers were configured under
 * `framework.http_client.default_options.headers`, so they went out on calls to
 * GoPay/ARES/Fakturoid and never reached a single visitor, while the config
 * looked correct. Asserting against real responses is the only way that stays
 * caught.
 */
final class SecurityHeadersTest extends WebTestCase
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
    }

    #[DataProvider('pageProvider')]
    public function testHeadersReachTheBrowser(string $uri): void
    {
        $client = static::createClient();
        $client->request('GET', $uri);

        $headers = $client->getResponse()->headers;

        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'), $uri);
        self::assertSame('strict-origin-when-cross-origin', $headers->get('Referrer-Policy'), $uri);
        self::assertSame('DENY', $headers->get('X-Frame-Options'), $uri);
    }

    public function testHstsIsSentOverHttpsOnly(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://localhost/');
        self::assertFalse(
            $client->getResponse()->headers->has('Strict-Transport-Security'),
            'HSTS nesmí odcházet po nezabezpečeném spojení (RFC 6797 § 7.2).',
        );

        $client->request('GET', 'https://localhost/');
        self::assertSame(
            'max-age=31536000; includeSubDomains',
            $client->getResponse()->headers->get('Strict-Transport-Security'),
        );
    }

    /**
     * The GoPay return routes deliberately go without X-Frame-Options until it
     * is confirmed whether GoPay opens return_url top-level or inside its
     * gateway iframe. Everything else must still carry it.
     */
    public function testGoPayReturnRoutesAreExemptFromFrameOptions(): void
    {
        $client = static::createClient();

        // A bogus order id: the controller 404s, but the listener has already
        // run and the route is resolved — which is exactly what we're asserting.
        $client->request('GET', '/objednavka/00000000-0000-0000-0000-000000000000/platba/navrat');

        self::assertFalse(
            $client->getResponse()->headers->has('X-Frame-Options'),
            'Návratová routa GoPay musí zůstat bez X-Frame-Options, jinak hrozí rozbití platby.',
        );
        // The harmless headers must still be there.
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
    }

    public function testThePaymentPageItselfKeepsTheHeader(): void
    {
        $client = static::createClient();

        // Our payment page frames GoPay, not the other way round, so it is safe
        // to protect — and it is the page worth protecting.
        $client->request('GET', '/objednavka/00000000-0000-0000-0000-000000000000/platba');

        self::assertSame('DENY', $client->getResponse()->headers->get('X-Frame-Options'));
    }
}
