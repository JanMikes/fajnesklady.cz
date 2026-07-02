<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AuditLog;
use App\Entity\Order;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminOrderResendSigningLinkControllerTest extends WebTestCase
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
        $order = $this->findOrderByStorageNumber('B1');

        $this->client->request('POST', $this->url($order));

        $this->assertResponseRedirects('/login');
    }

    public function testDeniedForNonAdminUser(): void
    {
        $order = $this->findOrderByStorageNumber('B1');
        $this->client->loginUser($this->findUserByEmail('user@example.com'), 'main');

        $this->client->request('POST', $this->url($order));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testResendPersistsAuditRow(): void
    {
        $order = $this->findOrderByStorageNumber('B1');
        // Must match the public_customer_signing route requirement [a-f0-9]{64}
        // — the e-mail handler generates the signing URL from it.
        $order->setSigningToken(str_repeat('ab', 32));
        $this->entityManager->flush();

        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');
        $this->client->request('POST', $this->url($order));

        $this->assertResponseRedirects('/portal/admin/orders/'.$order->id->toRfc4122());

        // Regression: the audit row used to be persisted AFTER the event-bus
        // dispatch had already flushed, so it was silently lost every time.
        $auditLog = $this->findManualEmailAuditRow($order->id->toRfc4122());
        $this->assertInstanceOf(AuditLog::class, $auditLog);
        $this->assertSame('signing_link', $auditLog->payload['email_type']);
    }

    public function testNoAuditRowWhenOrderHasNoSigningToken(): void
    {
        $order = $this->findOrderByStorageNumber('B1');

        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');
        $this->client->request('POST', $this->url($order));

        $this->assertResponseRedirects('/portal/admin/orders/'.$order->id->toRfc4122());
        $this->assertNull($this->findManualEmailAuditRow($order->id->toRfc4122()));
    }

    private function url(Order $order): string
    {
        return '/portal/admin/orders/'.$order->id->toRfc4122().'/resend-signing-link';
    }

    private function findManualEmailAuditRow(string $orderId): ?AuditLog
    {
        $this->entityManager->clear();

        return $this->entityManager->createQueryBuilder()
            ->select('al')
            ->from(AuditLog::class, 'al')
            ->where('al.entityType = :entityType')
            ->andWhere('al.eventType = :eventType')
            ->andWhere('al.entityId = :entityId')
            ->setParameter('entityType', 'order')
            ->setParameter('eventType', 'admin_manual_email_sent')
            ->setParameter('entityId', $orderId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function findOrderByStorageNumber(string $number): Order
    {
        $order = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->join('o.storage', 's')
            ->where('s.number = :number')
            ->setParameter('number', $number)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($order instanceof Order);

        return $order;
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
