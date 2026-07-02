<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Portal\User;

use App\Entity\AuditLog;
use App\Entity\Contract;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ContractTerminateControllerTest extends WebTestCase
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

    public function testRequiresAuthentication(): void
    {
        $contract = $this->findUnlimitedContract();

        $this->client->request('POST', $this->url($contract));

        $this->assertResponseRedirects('/login');
    }

    public function testDeniedForNonOwner(): void
    {
        $contract = $this->findUnlimitedContract();
        $this->client->loginUser($this->findUserByEmail('tenant@example.com'), 'main');

        $this->client->request('POST', $this->url($contract));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testTerminatePersistsRecurringCancelledAuditRow(): void
    {
        $contract = $this->findUnlimitedContract();
        $contractId = $contract->id->toRfc4122();
        $this->client->loginUser($this->findUserByEmail('user@example.com'), 'main');

        $this->client->request('POST', $this->url($contract));

        $this->assertResponseRedirects('/portal/objednavky/'.$contract->order->id->toRfc4122());

        // Regression: the audit row used to be persisted in the controller
        // AFTER the command bus had already flushed, so it was silently lost
        // on every tenant-initiated termination. It now lives in
        // CancelRecurringPaymentHandler, inside the command's transaction.
        $this->entityManager->clear();
        $auditLog = $this->entityManager->createQueryBuilder()
            ->select('al')
            ->from(AuditLog::class, 'al')
            ->where('al.entityType = :entityType')
            ->andWhere('al.eventType = :eventType')
            ->andWhere('al.entityId = :entityId')
            ->setParameter('entityType', 'contract')
            ->setParameter('eventType', 'recurring_cancelled')
            ->setParameter('entityId', $contractId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $this->assertInstanceOf(AuditLog::class, $auditLog);
    }

    private function url(Contract $contract): string
    {
        return '/portal/smlouvy/'.$contract->id->toRfc4122().'/ukoncit';
    }

    private function findUnlimitedContract(): Contract
    {
        // REF_CONTRACT_UNLIMITED — owned by user@example.com, has an active
        // recurring payment (gopay-parent-debug-unlimited), occupies C1.
        $contract = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->join('c.storage', 's')
            ->where('s.number = :number')
            ->setParameter('number', 'C1')
            ->getQuery()
            ->getOneOrNullResult();
        \assert($contract instanceof Contract);

        return $contract;
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
        \assert($user instanceof User);

        return $user;
    }
}
