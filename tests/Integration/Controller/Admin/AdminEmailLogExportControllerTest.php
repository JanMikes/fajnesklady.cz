<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\EmailLog;
use App\Enum\EmailLogStatus;
use App\Tests\Integration\Controller\ExcelExportTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class AdminEmailLogExportControllerTest extends WebTestCase
{
    use ExcelExportTestTrait;

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

    public function testAnonymousIsRedirected(): void
    {
        $this->client->request('GET', '/portal/admin/email-log/export');

        $this->assertResponseRedirects('/login');
    }

    public function testNonAdminGetsForbidden(): void
    {
        $this->client->loginUser($this->findUserByEmail($this->entityManager, 'landlord@example.com'), 'main');
        $this->client->request('GET', '/portal/admin/email-log/export');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminExportContainsCreatedLog(): void
    {
        $this->client->loginUser($this->findUserByEmail($this->entityManager, 'admin@example.com'), 'main');

        $log = $this->createLog('export-test@example.com', 'Test předmět', null, EmailLogStatus::SENT);
        $this->entityManager->persist($log);
        $this->entityManager->flush();

        $this->client->request('GET', '/portal/admin/email-log/export');

        $body = $this->assertXlsxResponse($this->client);
        $rows = $this->readXlsxRows($body);

        self::assertSame('Odesláno', $rows[0][0]);
        self::assertSame('Příjemce', $rows[0][1]);
        self::assertTrue($this->rowsContainCellValue($rows, 'export-test@example.com'));
        self::assertTrue($this->rowsContainCellValue($rows, 'Test předmět'));
    }

    public function testStatusFilterRestrictsResults(): void
    {
        $this->client->loginUser($this->findUserByEmail($this->entityManager, 'admin@example.com'), 'main');

        $sent = $this->createLog('export-status-sent@example.com', 'Sent body', null, EmailLogStatus::SENT);
        $failed = $this->createLog('export-status-failed@example.com', 'Failed body', null, EmailLogStatus::FAILED, errorMessage: 'boom');
        $this->entityManager->persist($sent);
        $this->entityManager->persist($failed);
        $this->entityManager->flush();

        $this->client->request('GET', '/portal/admin/email-log/export?status=failed');

        $body = $this->assertXlsxResponse($this->client);
        $rows = $this->readXlsxRows($body);

        self::assertTrue($this->rowsContainCellValue($rows, 'export-status-failed@example.com'));
        self::assertFalse($this->rowsContainCellValue($rows, 'export-status-sent@example.com'));
    }

    private function createLog(
        string $toEmail,
        string $subject,
        ?string $templateName,
        EmailLogStatus $status,
        ?string $errorMessage = null,
    ): EmailLog {
        return new EmailLog(
            id: Uuid::v7(),
            attemptedAt: $this->clock->now(),
            status: $status,
            errorMessage: $errorMessage,
            fromEmail: 'noreply@fajnesklady.cz',
            fromName: 'Fajnesklady.cz',
            toAddresses: [['email' => $toEmail, 'name' => null]],
            ccAddresses: null,
            bccAddresses: null,
            replyToAddresses: null,
            subject: $subject,
            htmlBody: '<p>Body</p>',
            textBody: null,
            templateName: $templateName,
            attachments: null,
            messageId: null,
        );
    }
}
