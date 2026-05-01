<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\EmailLog;
use App\Entity\User;
use App\Enum\EmailLogStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class AdminEmailLogControllerTest extends WebTestCase
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

    public function testListRequiresAuthentication(): void
    {
        $this->client->request('GET', '/portal/admin/email-log');

        $this->assertResponseRedirects('/login');
    }

    public function testListDeniedForRegularUser(): void
    {
        $user = $this->findUserByEmail('user@example.com');
        $this->client->loginUser($user, 'main');

        $this->client->request('GET', '/portal/admin/email-log');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testListDeniedForLandlord(): void
    {
        $landlord = $this->findUserByEmail('landlord@example.com');
        $this->client->loginUser($landlord, 'main');

        $this->client->request('GET', '/portal/admin/email-log');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testListAccessibleByAdmin(): void
    {
        $admin = $this->findUserByEmail('admin@example.com');
        $this->client->loginUser($admin, 'main');

        $this->createLog('list-test-1@example.com', 'Vítací e-mail', 'email/welcome.html.twig', EmailLogStatus::SENT);
        $this->createLog('list-test-2@example.com', 'Faktura', 'email/invoice.html.twig', EmailLogStatus::FAILED, errorMessage: 'SMTP timeout');

        $this->client->request('GET', '/portal/admin/email-log');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Odeslané e-maily');
        $this->assertSelectorTextContains('body', 'list-test-1@example.com');
        $this->assertSelectorTextContains('body', 'list-test-2@example.com');
        $this->assertSelectorTextContains('body', 'Vítací e-mail');
    }

    public function testFilterByStatusReturnsOnlyMatchingRows(): void
    {
        $admin = $this->findUserByEmail('admin@example.com');
        $this->client->loginUser($admin, 'main');

        $this->createLog('filter-status-sent@example.com', 'Sent message', null, EmailLogStatus::SENT);
        $this->createLog('filter-status-failed@example.com', 'Failed message', null, EmailLogStatus::FAILED, errorMessage: 'boom');

        $this->client->request('GET', '/portal/admin/email-log?status=failed');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'filter-status-failed@example.com');
        $this->assertSelectorTextNotContains('body', 'filter-status-sent@example.com');
    }

    public function testFilterByTemplate(): void
    {
        $admin = $this->findUserByEmail('admin@example.com');
        $this->client->loginUser($admin, 'main');

        $this->createLog('template-a@example.com', 'A', 'email/template_a.html.twig', EmailLogStatus::SENT);
        $this->createLog('template-b@example.com', 'B', 'email/template_b.html.twig', EmailLogStatus::SENT);

        $this->client->request('GET', '/portal/admin/email-log?template=email%2Ftemplate_a.html.twig');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'template-a@example.com');
        $this->assertSelectorTextNotContains('body', 'template-b@example.com');
    }

    public function testFilterByRecipient(): void
    {
        $admin = $this->findUserByEmail('admin@example.com');
        $this->client->loginUser($admin, 'main');

        $this->createLog('alice-rec@example.com', 'Hello Alice', null, EmailLogStatus::SENT);
        $this->createLog('bob-rec@example.com', 'Hello Bob', null, EmailLogStatus::SENT);

        $this->client->request('GET', '/portal/admin/email-log?recipient=alice');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'alice-rec@example.com');
        $this->assertSelectorTextNotContains('body', 'bob-rec@example.com');
    }

    public function testDetailDeniedForNonAdmin(): void
    {
        $user = $this->findUserByEmail('user@example.com');
        $this->client->loginUser($user, 'main');

        $log = $this->createLog('detail-denied@example.com', 'Denied', null, EmailLogStatus::SENT);

        $this->client->request('GET', '/portal/admin/email-log/'.$log->id->toRfc4122());

        $this->assertResponseStatusCodeSame(403);
    }

    public function testDetailRendersFullEmail(): void
    {
        $admin = $this->findUserByEmail('admin@example.com');
        $this->client->loginUser($admin, 'main');

        $log = $this->createLog(
            'detail-render@example.com',
            'Předmět e-mailu',
            'email/welcome.html.twig',
            EmailLogStatus::SENT,
            htmlBody: '<p>Hello world</p>',
            attachments: [
                ['name' => 'smlouva.pdf', 'sizeBytes' => 1234, 'mimeType' => 'application/pdf'],
            ],
        );

        $crawler = $this->client->request('GET', '/portal/admin/email-log/'.$log->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Předmět e-mailu');
        $this->assertSelectorTextContains('body', 'detail-render@example.com');
        $this->assertSelectorTextContains('body', 'smlouva.pdf');
        $this->assertSelectorTextContains('body', 'application/pdf');
        $this->assertSelectorExists('iframe[sandbox=""]');
        // The iframe srcdoc must contain the rendered HTML, escaped as html_attr.
        $iframe = $crawler->filter('iframe[sandbox=""]')->first();
        $this->assertNotEmpty($iframe->attr('srcdoc'));
    }

    public function testFailedLogShowsErrorMessage(): void
    {
        $admin = $this->findUserByEmail('admin@example.com');
        $this->client->loginUser($admin, 'main');

        $log = $this->createLog(
            'failed-detail@example.com',
            'Selhal',
            null,
            EmailLogStatus::FAILED,
            errorMessage: 'Connection refused on port 25',
        );

        $this->client->request('GET', '/portal/admin/email-log/'.$log->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Connection refused on port 25');
    }

    public function testDetailReturns404ForUnknownId(): void
    {
        $admin = $this->findUserByEmail('admin@example.com');
        $this->client->loginUser($admin, 'main');

        $unknownId = Uuid::v7()->toRfc4122();
        $this->client->request('GET', '/portal/admin/email-log/'.$unknownId);

        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * @param ?array<int, array{name: string, sizeBytes: int, mimeType: string}> $attachments
     */
    private function createLog(
        string $toEmail,
        string $subject,
        ?string $templateName,
        EmailLogStatus $status,
        ?string $errorMessage = null,
        string $htmlBody = '<p>Body</p>',
        ?array $attachments = null,
    ): EmailLog {
        $log = new EmailLog(
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
            htmlBody: $htmlBody,
            textBody: null,
            templateName: $templateName,
            attachments: $attachments,
            messageId: null,
        );

        $this->entityManager->persist($log);
        $this->entityManager->flush();

        return $log;
    }

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User, sprintf('User with email "%s" not found in fixtures', $email));

        return $user;
    }
}
