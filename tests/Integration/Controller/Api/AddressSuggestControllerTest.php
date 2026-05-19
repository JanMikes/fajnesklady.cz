<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Api;

use App\Service\Address\AddressValidator;
use App\Tests\Mock\MockAddressValidator;
use App\Value\Address\AddressSuggestion;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class AddressSuggestControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private MockAddressValidator $addressValidator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();

        $addressValidator = static::getContainer()->get(AddressValidator::class);
        \assert($addressValidator instanceof MockAddressValidator);
        $this->addressValidator = $addressValidator;
        $this->addressValidator->reset();

        // Reset the per-IP limiter so the rate-limit case below doesn't bleed
        // into the others.
        $limiter = static::getContainer()->get('test.limiter.address_suggest');
        \assert($limiter instanceof RateLimiterFactoryInterface);
        $limiter->create('127.0.0.1')->reset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testReturnsEmptyArrayForShortQuery(): void
    {
        $this->client->request('GET', '/api/address/suggest?q=ab');

        self::assertResponseIsSuccessful();
        self::assertResponseFormatSame('json');

        $data = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['suggestions' => []], $data);
    }

    public function testReturnsParsedSuggestions(): void
    {
        $this->addressValidator->willReturn([
            new AddressSuggestion(
                street: 'Vinohradská',
                houseNumber: '52',
                city: 'Praha',
                postalCode: '12000',
                displayLabel: 'Vinohradská 52, 120 00 Praha',
            ),
        ]);

        $this->client->request('GET', '/api/address/suggest?q=Vinohrad');

        self::assertResponseIsSuccessful();
        self::assertResponseFormatSame('json');

        $data = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([
            'suggestions' => [
                [
                    'street' => 'Vinohradská',
                    'houseNumber' => '52',
                    'city' => 'Praha',
                    'postalCode' => '12000',
                    'displayLabel' => 'Vinohradská 52, 120 00 Praha',
                ],
            ],
        ], $data);
    }

    public function testReturnsTooManyRequestsAfterRateLimitExhausted(): void
    {
        $this->addressValidator->willReturn([]);

        $rateLimitedSeen = false;

        // Limiter is configured to 300 per hour per IP.
        for ($i = 0; $i < 310; ++$i) {
            $this->client->request('GET', '/api/address/suggest?q=Praha');
            if (429 === $this->client->getResponse()->getStatusCode()) {
                $rateLimitedSeen = true;

                break;
            }
        }

        self::assertTrue($rateLimitedSeen, 'Expected rate limit (HTTP 429) to be hit within 310 attempts.');
    }
}
