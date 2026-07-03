<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\DataFixtures\HandoverProtocolFixtures;
use App\Entity\AuditLog;
use App\Entity\HandoverProtocol;
use App\Entity\User;
use App\Enum\HandoverStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Spec 083 — admin-only POST that waives the tenant side of a handover protocol.
 */
class AdminHandoverSkipTenantControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
        $this->clock = static::getContainer()->get(ClockInterface::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testRequiresAuthentication(): void
    {
        $protocol = $this->findPendingFixtureProtocol();

        $this->client->request('POST', $this->url($protocol));

        $this->assertResponseRedirects('/login');
    }

    public function testDeniedForRegularUser(): void
    {
        $protocol = $this->findPendingFixtureProtocol();
        $this->client->loginUser($this->findUserByEmail('user@example.com'), 'main');

        $this->client->request('POST', $this->url($protocol));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testDeniedForLandlord(): void
    {
        $protocol = $this->findPendingFixtureProtocol();
        $this->client->loginUser($this->findUserByEmail('landlord@example.com'), 'main');

        $this->client->request('POST', $this->url($protocol));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testNotFoundForUnknownProtocol(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $this->client->request('POST', '/portal/admin/predavaci-protokol/'.Uuid::v7()->toRfc4122().'/preskocit-najemce');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testAdminSkipsPendingTenantSide(): void
    {
        $protocol = $this->findPendingFixtureProtocol();
        $admin = $this->findUserByEmail('admin@example.com');
        $this->client->loginUser($admin, 'main');

        $this->client->request('POST', $this->url($protocol));

        $this->assertResponseRedirects('/portal/admin/predavaci-protokol/'.$protocol->id->toRfc4122());

        $reloaded = $this->reload($protocol);
        $this->assertNotNull($reloaded->tenantSkippedAt);
        $this->assertNotNull($reloaded->tenantSkippedBy);
        $this->assertTrue($reloaded->tenantSkippedBy->id->equals($admin->id));
        $this->assertSame(HandoverStatus::TENANT_COMPLETED, $reloaded->status);
        $this->assertNull($reloaded->completedAt, 'Landlord side still open — protocol must not complete.');
        $this->assertFalse($reloaded->needsTenantCompletion());

        $auditLog = $this->findAuditRow($protocol->id->toRfc4122(), 'tenant_skipped');
        $this->assertInstanceOf(AuditLog::class, $auditLog);
        $this->assertSame($admin->id->toRfc4122(), $auditLog->payload['skipped_by']);
        $this->assertSame(HandoverStatus::TENANT_COMPLETED->value, $auditLog->payload['status']);

        $this->client->followRedirect();
        $this->assertSelectorTextContains('[data-flash-type="success"]', 'Strana nájemce byla přeskočena.');
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Strana nájemce byla přeskočena administrátorem', $body);
        $this->assertStringContainsString('Přeskočeno', $body);
        $this->assertStringNotContainsString('Přeskočit stranu nájemce', $body);
    }

    public function testSkipCompletesProtocolWhenLandlordAlreadyFilled(): void
    {
        $protocol = $this->findPendingFixtureProtocol();
        $protocol->completeLandlordSide('Sklad převzat, vše v pořádku.', null, $this->clock->now());
        $this->entityManager->flush();

        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $this->client->request('POST', $this->url($protocol));

        $this->assertResponseRedirects('/portal/admin/predavaci-protokol/'.$protocol->id->toRfc4122());

        $reloaded = $this->reload($protocol);
        $this->assertSame(HandoverStatus::COMPLETED, $reloaded->status);
        $this->assertNotNull($reloaded->completedAt);
        $this->assertNotNull($reloaded->tenantSkippedAt);

        $this->client->followRedirect();
        $this->assertSelectorTextContains('[data-flash-type="success"]', 'Protokol je tím dokončen.');
    }

    public function testUserPortalPageAfterSkipHidesTenantFormEvenForAdmin(): void
    {
        $protocol = $this->findPendingFixtureProtocol();
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $this->client->request('POST', $this->url($protocol));
        $this->assertResponseRedirects();

        // The "Vyplnit za nájemce" target page: the voter short-circuits admins
        // to canComplete=true, but the skipped branch must win over the form.
        $this->client->request('GET', '/portal/predavaci-protokol/'.$protocol->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Vyplnění vaší strany protokolu nebylo vyžadováno.', $body);
        $this->assertStringNotContainsString('Odeslat předávací protokol', $body);
    }

    public function testSkipRejectedWhenTenantAlreadyCompleted(): void
    {
        $protocol = $this->findTenantCompletedFixtureProtocol();
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $this->client->request('POST', $this->url($protocol));

        $this->assertResponseRedirects('/portal/admin/predavaci-protokol/'.$protocol->id->toRfc4122());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('[data-flash-type="error"]', 'Nájemce již předávací protokol vyplnil.');

        $reloaded = $this->reload($protocol);
        $this->assertNull($reloaded->tenantSkippedAt);
        $this->assertNull($reloaded->tenantSkippedBy);
        $this->assertNull($this->findAuditRow($protocol->id->toRfc4122(), 'tenant_skipped'));
    }

    private function url(HandoverProtocol $protocol): string
    {
        return '/portal/admin/predavaci-protokol/'.$protocol->id->toRfc4122().'/preskocit-najemce';
    }

    private function reload(HandoverProtocol $protocol): HandoverProtocol
    {
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(HandoverProtocol::class, $protocol->id);
        \assert($reloaded instanceof HandoverProtocol);

        return $reloaded;
    }

    private function findPendingFixtureProtocol(): HandoverProtocol
    {
        // REF_HANDOVER_PENDING sits on the active B3 fixture contract.
        $protocol = $this->entityManager->createQueryBuilder()
            ->select('hp')
            ->from(HandoverProtocol::class, 'hp')
            ->join('hp.contract', 'c')
            ->join('c.storage', 's')
            ->where('s.number = :number')
            ->setParameter('number', 'B3')
            ->getQuery()
            ->getSingleResult();
        \assert($protocol instanceof HandoverProtocol, 'Expected fixture protocol on B3 — see '.HandoverProtocolFixtures::class);

        return $protocol;
    }

    private function findTenantCompletedFixtureProtocol(): HandoverProtocol
    {
        // REF_HANDOVER_TENANT_COMPLETED is the only fixture with a filled tenant side.
        $protocol = $this->entityManager->createQueryBuilder()
            ->select('hp')
            ->from(HandoverProtocol::class, 'hp')
            ->where('hp.tenantCompletedAt IS NOT NULL')
            ->getQuery()
            ->getSingleResult();
        \assert($protocol instanceof HandoverProtocol, 'Expected tenant-completed fixture protocol — see '.HandoverProtocolFixtures::class);

        return $protocol;
    }

    private function findAuditRow(string $entityId, string $eventType): ?AuditLog
    {
        return $this->entityManager->createQueryBuilder()
            ->select('al')
            ->from(AuditLog::class, 'al')
            ->where('al.entityType = :entityType')
            ->andWhere('al.eventType = :eventType')
            ->andWhere('al.entityId = :entityId')
            ->setParameter('entityType', 'handover')
            ->setParameter('eventType', $eventType)
            ->setParameter('entityId', $entityId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($user instanceof User, sprintf('User with email "%s" not found in fixtures', $email));

        return $user;
    }
}
