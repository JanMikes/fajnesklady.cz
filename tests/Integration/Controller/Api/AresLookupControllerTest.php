<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Api;

use App\Service\AresLookup;
use App\Tests\Mock\MockAresLookup;
use App\Value\AresResult;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AresLookupControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private MockAresLookup $aresLookup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();

        $aresLookup = static::getContainer()->get(AresLookup::class);
        \assert($aresLookup instanceof MockAresLookup);
        $this->aresLookup = $aresLookup;
        $this->aresLookup->reset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testReturnsCompanyDataForValidIco(): void
    {
        $this->aresLookup->willReturn(new AresResult(
            companyName: 'Google Czech Republic, s.r.o.',
            companyId: '27604977',
            companyVatId: 'CZ27604977',
            street: 'Stroupežnického 3191/17',
            city: 'Praha',
            postalCode: '15000',
        ));

        $this->client->request('GET', '/api/ares/27604977');

        self::assertResponseIsSuccessful();
        self::assertResponseFormatSame('json');

        $data = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([
            'companyName' => 'Google Czech Republic, s.r.o.',
            'companyVatId' => 'CZ27604977',
            'billingStreet' => 'Stroupežnického 3191/17',
            'billingCity' => 'Praha',
            'billingPostalCode' => '15000',
        ], $data);
    }

    public function testReturnsNotFoundWhenAresHasNoSubject(): void
    {
        $this->aresLookup->willReturn(null);

        $this->client->request('GET', '/api/ares/12345678');

        self::assertResponseStatusCodeSame(404);
        $data = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['error' => 'not_found'], $data);
    }

    public function testReturnsServiceUnavailableWhenAresFails(): void
    {
        $this->aresLookup->willThrowUnavailable();

        $this->client->request('GET', '/api/ares/27604977');

        self::assertResponseStatusCodeSame(503);
        $data = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['error' => 'unavailable'], $data);
    }

    public function testReturnsUnprocessableForNonNumericIco(): void
    {
        // Non-numeric IČO is rejected by the route requirement before hitting the controller (returns 404).
        $this->client->request('GET', '/api/ares/abc');
        self::assertResponseStatusCodeSame(404);
    }

    public function testReturnsUnprocessableForWrongLengthIco(): void
    {
        $this->aresLookup->willReturn(null);

        // 7 digits passes the route requirement (\d{1,12}) but fails the strict 8-digit check in the controller.
        $this->client->request('GET', '/api/ares/1234567');

        self::assertResponseStatusCodeSame(422);
        $data = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['error' => 'invalid_format'], $data);
    }

    public function testIsRateLimitedAfterTooManyRequests(): void
    {
        $this->aresLookup->willReturn(null);

        $rateLimitedSeen = false;

        // The limiter is configured to 60 per hour per IP.
        for ($i = 0; $i < 70; ++$i) {
            $this->client->request('GET', '/api/ares/12345678');
            if (429 === $this->client->getResponse()->getStatusCode()) {
                $rateLimitedSeen = true;
                break;
            }
        }

        self::assertTrue($rateLimitedSeen, 'Expected rate limit (HTTP 429) to be hit within 70 attempts.');
    }
}
