<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Public;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

class PaymentNotificationControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $container = static::getContainer();

        // Reset the gopay_webhook bucket for each IP we touch so prior cases
        // (and the rate-limit-exhaustion test below, which intentionally
        // exhausts the bucket) don't bleed into other tests. The cache
        // backing the limiter is filesystem-persistent across kernel reboots.
        $limiter = $container->get('test.limiter.gopay_webhook');
        \assert($limiter instanceof RateLimiterFactoryInterface);
        foreach (['127.0.0.1', '10.0.0.1', '10.0.0.2'] as $ip) {
            $limiter->create($ip)->reset();
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    /**
     * Asserts the controller reaches the dispatch path. The handler returns a
     * 200 with body 'OK' only when commandBus->dispatch() completed; on a
     * processing exception the controller catches and writes 'Processed'
     * (also 200, by design — GoPay must not retry on processing errors).
     * Distinguishing 'OK' vs 'Processed' is the load-bearing assertion: it
     * proves the command was dispatched AND the handler ran cleanly.
     */
    public function testWebhookDispatchesCommandForValidPaymentId(): void
    {
        $this->client->request('GET', '/webhook/gopay?id=gp_unknown_42');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('OK', (string) $this->client->getResponse()->getContent());
    }

    public function testWebhookReturns400WhenPaymentIdMissing(): void
    {
        $this->client->request('GET', '/webhook/gopay');

        self::assertResponseStatusCodeSame(400);
    }

    public function testWebhookReturns429AndDoesNotDispatchCommandWhenRateLimitExceeded(): void
    {
        // Limiter is configured at limit=60 burst, refill 60/min. Fire 60
        // requests in the same tick to drain the bucket, then a 61st to
        // observe the 429.
        for ($i = 0; $i < 60; ++$i) {
            $this->client->request(
                'GET',
                '/webhook/gopay?id=gp_burst_'.$i,
                [],
                [],
                ['REMOTE_ADDR' => '10.0.0.1'],
            );
            self::assertResponseStatusCodeSame(200);
        }

        // 61st request — bucket exhausted. The controller short-circuits with
        // 429 + empty body BEFORE reaching commandBus->dispatch(); the 'OK'
        // response from the dispatch path is the only way the body becomes
        // 'OK', so empty-body + 429 verifies the command was not dispatched.
        $this->client->request(
            'GET',
            '/webhook/gopay?id=gp_burst_60',
            [],
            [],
            ['REMOTE_ADDR' => '10.0.0.1'],
        );

        self::assertResponseStatusCodeSame(429);
        self::assertSame(
            '',
            (string) $this->client->getResponse()->getContent(),
            'Rate-limited request must not invoke the command handler — its 200/OK body would prove dispatch ran.',
        );
    }

    public function testWebhookRateLimitsArePerIp(): void
    {
        // Exhaust the bucket for IP-A.
        for ($i = 0; $i < 60; ++$i) {
            $this->client->request(
                'GET',
                '/webhook/gopay?id=gp_a_'.$i,
                [],
                [],
                ['REMOTE_ADDR' => '10.0.0.1'],
            );
        }

        // IP-A's 61st is rate-limited.
        $this->client->request(
            'GET',
            '/webhook/gopay?id=gp_a_overflow',
            [],
            [],
            ['REMOTE_ADDR' => '10.0.0.1'],
        );
        self::assertResponseStatusCodeSame(429);

        // IP-B has its own fresh bucket — first request must succeed.
        $this->client->request(
            'GET',
            '/webhook/gopay?id=gp_b_first',
            [],
            [],
            ['REMOTE_ADDR' => '10.0.0.2'],
        );
        self::assertResponseStatusCodeSame(200);
        self::assertSame('OK', (string) $this->client->getResponse()->getContent());
    }
}
