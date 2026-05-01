<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\EmailLog;
use App\Enum\EmailLogStatus;
use App\Repository\EmailLogRepository;
use App\Service\EmailLogger;
use App\Tests\Support\PredictableIdentityProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\RawMessage;

#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class EmailLoggerTest extends TestCase
{
    private MockClock $clock;
    private PredictableIdentityProvider $identity;
    /** @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2025-06-15 12:00:00 UTC');
        $this->identity = new PredictableIdentityProvider();
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testSentEventIsLoggedWithFullEmailMetadata(): void
    {
        $captured = [];
        $repository = $this->createCapturingRepository($captured);
        $logger = new EmailLogger($repository, $this->identity, $this->clock, $this->logger);

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to(new Address('user@example.com', 'Jan Novák'))
            ->cc(new Address('admin@example.com'))
            ->subject('Vítejte')
            ->htmlTemplate('email/welcome.html.twig')
            ->html('<p>Hello</p>')
            ->text('Hello');

        $email->attach('PDFCONTENT', 'smlouva.pdf', 'application/pdf');

        $logger->onMessage(new MessageEvent($email, Envelope::create($email), 'test'));
        $logger->onSent(new SentMessageEvent($this->buildSentMessage($email)));

        $this->assertCount(1, $captured);
        $log = $captured[0];
        $this->assertSame(EmailLogStatus::SENT, $log->status);
        $this->assertNull($log->errorMessage);
        $this->assertSame('noreply@fajnesklady.cz', $log->fromEmail);
        $this->assertSame('Fajnesklady.cz', $log->fromName);
        $this->assertSame([['email' => 'user@example.com', 'name' => 'Jan Novák']], $log->toAddresses);
        $this->assertSame([['email' => 'admin@example.com', 'name' => null]], $log->ccAddresses);
        $this->assertNull($log->bccAddresses);
        $this->assertNull($log->replyToAddresses);
        $this->assertSame('Vítejte', $log->subject);
        $this->assertSame('<p>Hello</p>', $log->htmlBody);
        $this->assertSame('Hello', $log->textBody);
        $this->assertSame('email/welcome', $log->templateName);
        $this->assertNotNull($log->attachments);
        $this->assertCount(1, $log->attachments);
        $this->assertSame('smlouva.pdf', $log->attachments[0]['name']);
        $this->assertSame('application/pdf', $log->attachments[0]['mimeType']);
        $this->assertSame(strlen('PDFCONTENT'), $log->attachments[0]['sizeBytes']);
        $this->assertSame($this->clock->now()->getTimestamp(), $log->attemptedAt->getTimestamp());
    }

    public function testFailedEventIsLoggedWithErrorMessage(): void
    {
        $captured = [];
        $repository = $this->createCapturingRepository($captured);
        $logger = new EmailLogger($repository, $this->identity, $this->clock, $this->logger);

        $email = (new Email())
            ->from(new Address('noreply@fajnesklady.cz'))
            ->to(new Address('user@example.com'))
            ->subject('Test')
            ->html('<p>Body</p>');

        $logger->onFailed(new FailedMessageEvent($email, new \RuntimeException('SMTP connection refused')));

        $this->assertCount(1, $captured);
        $log = $captured[0];
        $this->assertSame(EmailLogStatus::FAILED, $log->status);
        $this->assertSame('SMTP connection refused', $log->errorMessage);
        $this->assertNull($log->templateName);
    }

    public function testRawNonEmailMessageIsNotLogged(): void
    {
        $captured = [];
        $repository = $this->createCapturingRepository($captured);
        $logger = new EmailLogger($repository, $this->identity, $this->clock, $this->logger);

        $rawMessage = new RawMessage('raw payload');

        $logger->onFailed(new FailedMessageEvent($rawMessage, new \RuntimeException('boom')));

        $this->assertCount(0, $captured);
    }

    public function testRepositoryFailureIsSwallowedAndLogged(): void
    {
        $repository = $this->createMock(EmailLogRepository::class);
        $repository->method('save')->willThrowException(new \RuntimeException('DB unavailable'));
        $logger = new EmailLogger($repository, $this->identity, $this->clock, $this->logger);

        $this->logger->expects(self::once())
            ->method('error')
            ->with(
                'EmailLogger failed to persist email log',
                self::callback(fn (array $context): bool => isset($context['exception']) && $context['exception'] instanceof \Throwable),
            );

        $email = (new Email())
            ->from(new Address('noreply@fajnesklady.cz'))
            ->to(new Address('user@example.com'))
            ->subject('Test')
            ->html('<p>Body</p>');

        // Must not re-throw.
        $logger->onSent(new SentMessageEvent($this->buildSentMessage($email)));
    }

    public function testTextOnlyTemplatedEmailRecordsTextTemplate(): void
    {
        $captured = [];
        $repository = $this->createCapturingRepository($captured);
        $logger = new EmailLogger($repository, $this->identity, $this->clock, $this->logger);

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz'))
            ->to(new Address('user@example.com'))
            ->subject('Plain')
            ->textTemplate('email/plain.txt.twig')
            ->text('Plain text body');

        $logger->onMessage(new MessageEvent($email, Envelope::create($email), 'test'));
        $logger->onSent(new SentMessageEvent($this->buildSentMessage($email)));

        $this->assertCount(1, $captured);
        $log = $captured[0];
        $this->assertSame('email/plain', $log->templateName);
        $this->assertNull($log->htmlBody);
        $this->assertSame('Plain text body', $log->textBody);
    }

    public function testNonTemplatedEmailHasNoTemplateName(): void
    {
        $captured = [];
        $repository = $this->createCapturingRepository($captured);
        $logger = new EmailLogger($repository, $this->identity, $this->clock, $this->logger);

        $email = (new Email())
            ->from(new Address('noreply@fajnesklady.cz'))
            ->to(new Address('user@example.com'))
            ->subject('No template')
            ->html('<p>Body</p>');

        $logger->onSent(new SentMessageEvent($this->buildSentMessage($email)));

        $this->assertCount(1, $captured);
        $this->assertNull($captured[0]->templateName);
    }

    public function testQueuedMessageEventIsIgnoredSoTemplateIsCapturedOnlyOnRealSend(): void
    {
        $captured = [];
        $repository = $this->createCapturingRepository($captured);
        $logger = new EmailLogger($repository, $this->identity, $this->clock, $this->logger);

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz'))
            ->to(new Address('user@example.com'))
            ->subject('Hi')
            ->htmlTemplate('email/welcome.html.twig')
            ->html('<p>Hello</p>');

        // The queued=true event fires in Mailer::send before async dispatch — the
        // worker will receive a fresh deserialized Email instance, so capturing
        // here would leak state. We assert the listener no-ops in that case.
        $logger->onMessage(new MessageEvent($email, Envelope::create($email), 'test', true));
        $logger->onSent(new SentMessageEvent($this->buildSentMessage($email)));

        $this->assertCount(1, $captured);
        $this->assertNull($captured[0]->templateName);
    }

    public function testAttachmentExtractedFromDataPart(): void
    {
        $captured = [];
        $repository = $this->createCapturingRepository($captured);
        $logger = new EmailLogger($repository, $this->identity, $this->clock, $this->logger);

        $email = (new Email())
            ->from(new Address('noreply@fajnesklady.cz'))
            ->to(new Address('user@example.com'))
            ->subject('With attachment')
            ->html('<p>Body</p>')
            ->addPart(new DataPart('IMAGEDATA', 'mapa.png', 'image/png'));

        $logger->onSent(new SentMessageEvent($this->buildSentMessage($email)));

        $this->assertCount(1, $captured);
        $attachments = $captured[0]->attachments;
        $this->assertNotNull($attachments);
        $this->assertCount(1, $attachments);
        $this->assertSame('mapa.png', $attachments[0]['name']);
        $this->assertSame('image/png', $attachments[0]['mimeType']);
        $this->assertSame(strlen('IMAGEDATA'), $attachments[0]['sizeBytes']);
    }

    /**
     * @param array<int, EmailLog> $captured reference array — the mock appends saved logs here
     */
    private function createCapturingRepository(array &$captured): EmailLogRepository
    {
        $repository = $this->createMock(EmailLogRepository::class);
        $repository->method('save')->willReturnCallback(static function (EmailLog $log) use (&$captured): void {
            $captured[] = $log;
        });

        return $repository;
    }

    private function buildSentMessage(Email $email): SentMessage
    {
        // @phpstan-ignore-next-line method.internal — testing only, no public factory exists.
        return new SentMessage($email, Envelope::create($email));
    }
}
