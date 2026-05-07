<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Portal;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CalendarControllerTest extends WebTestCase
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

    public function testDefaultViewRendersMonthGridWithToggle(): void
    {
        $this->loginAs('landlord@example.com');

        $this->client->request('GET', '/portal/calendar?year=2025&month=6');

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Měsíc', $body);
        $this->assertStringContainsString('Časová osa', $body);
        $this->assertStringContainsString('Červen 2025', $body);
        // Day cell badges and details are rendered server-side via <details>.
        $this->assertStringContainsString('<details', $body);
    }

    public function testTimelineViewRendersGantt(): void
    {
        $this->loginAs('landlord@example.com');

        $this->client->request('GET', '/portal/calendar?view=timeline&year=2025&month=6');

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('data-test="timeline-grid"', $body);
    }

    public function testEndingBadgeAppearsWhenContractFinishesInMonth(): void
    {
        // REF_CONTRACT_EXPIRING_7_DAYS ends on 2025-06-22 with the MockClock baseline (2025-06-15).
        $this->loginAs('landlord@example.com');

        $this->client->request('GET', '/portal/calendar?year=2025&month=6');

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('končí', $body);
    }

    public function testDefaultMonthAndYearComeFromClockNotServerTime(): void
    {
        // MockClock is pinned to 2025-06-15. Without query params the controller
        // must default to that month — proving it reads from the injected clock,
        // not PHP's `date()` (which would yield "now" in UTC and could roll the
        // month over for late-evening Europe/Prague visitors on the last day).
        $this->loginAs('landlord@example.com');

        $this->client->request('GET', '/portal/calendar');

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Červen 2025', $body);
    }

    public function testTerminatingContractShowsWarningIconInBottomStorageList(): void
    {
        // REF_CONTRACT_TERMINATING is an unlimited contract on storage E1
        // (Praha Jih, owned by landlord) with terminatesAt set ~30 days ahead.
        // The bottom storage list of the calendar must mirror the planning page
        // and render "do dd.mm.yyyy ⚠" for that row.
        $this->loginAs('landlord@example.com');

        $this->client->request('GET', '/portal/calendar?year=2025&month=6');

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('⚠', $body);
        $this->assertStringContainsString('ukončuje se', $body);
    }

    private function loginAs(string $email): void
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User);
        $this->client->loginUser($user, 'main');
    }
}
