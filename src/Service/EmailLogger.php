<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EmailLog;
use App\Enum\EmailLogStatus;
use App\Repository\EmailLogRepository;
use App\Service\Identity\ProvideIdentity;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Persists every outgoing email (and every attempt) into the email_log table.
 *
 * Failures inside this listener MUST NOT bubble up — logging is best-effort
 * and never blocks delivery. We log to monolog and move on.
 */
final readonly class EmailLogger
{
    public function __construct(
        private EmailLogRepository $repository,
        private ProvideIdentity $identity,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    #[AsEventListener]
    public function onSent(SentMessageEvent $event): void
    {
        // SentMessageEvent's getMessage() returns SentMessage; the original Email
        // (with body, template, etc.) is on getOriginalMessage().
        $this->logSafely(
            $event->getMessage()->getOriginalMessage(),
            EmailLogStatus::SENT,
            errorMessage: null,
        );
    }

    #[AsEventListener]
    public function onFailed(FailedMessageEvent $event): void
    {
        $this->logSafely(
            $event->getMessage(),
            EmailLogStatus::FAILED,
            errorMessage: $event->getError()->getMessage(),
        );
    }

    private function logSafely(RawMessage $message, EmailLogStatus $status, ?string $errorMessage): void
    {
        try {
            $log = $this->buildLog($message, $status, $errorMessage);
            if (null !== $log) {
                $this->repository->save($log);
            }
        } catch (\Throwable $e) {
            // NEVER re-throw — logging must not break sending.
            $this->logger->error('EmailLogger failed to persist email log', [
                'exception' => $e,
                'status' => $status->value,
            ]);
        }
    }

    private function buildLog(RawMessage $message, EmailLogStatus $status, ?string $errorMessage): ?EmailLog
    {
        if (!$message instanceof Email) {
            // We do not log raw (non-Email) messages such as Notifier SMS.
            return null;
        }

        $fromAddresses = $message->getFrom();
        $firstFrom = $fromAddresses[0] ?? null;

        $templateName = null;
        if ($message instanceof TemplatedEmail) {
            $templateName = $message->getHtmlTemplate() ?? $message->getTextTemplate();
        }

        $attachments = $this->extractAttachments($message);
        $messageId = $message->getHeaders()->get('Message-ID')?->getBodyAsString();

        return new EmailLog(
            id: $this->identity->next(),
            attemptedAt: $this->clock->now(),
            status: $status,
            errorMessage: $errorMessage,
            fromEmail: $firstFrom?->getAddress() ?? '',
            fromName: '' !== ($firstFrom?->getName() ?? '') ? $firstFrom?->getName() : null,
            toAddresses: $this->mapAddresses($message->getTo()) ?? [],
            ccAddresses: $this->mapAddresses($message->getCc()),
            bccAddresses: $this->mapAddresses($message->getBcc()),
            replyToAddresses: $this->mapAddresses($message->getReplyTo()),
            subject: $message->getSubject() ?? '',
            htmlBody: $this->stringifyBody($message->getHtmlBody()),
            textBody: $this->stringifyBody($message->getTextBody()),
            templateName: $templateName,
            attachments: $attachments,
            messageId: $messageId,
        );
    }

    /**
     * @param Address[] $addresses
     *
     * @return ?list<array{email: string, name: ?string}>
     */
    private function mapAddresses(array $addresses): ?array
    {
        if ([] === $addresses) {
            return null;
        }

        $mapped = [];
        foreach ($addresses as $address) {
            $name = $address->getName();
            $mapped[] = [
                'email' => $address->getAddress(),
                'name' => '' !== $name ? $name : null,
            ];
        }

        return $mapped;
    }

    /**
     * @return ?list<array{name: string, sizeBytes: int, mimeType: string}>
     */
    private function extractAttachments(Email $email): ?array
    {
        $parts = $email->getAttachments();
        if ([] === $parts) {
            return null;
        }

        $result = [];
        foreach ($parts as $part) {
            $name = $part->getFilename() ?? $part->getName() ?? 'attachment';

            $result[] = [
                'name' => $name,
                'sizeBytes' => strlen($part->getBody()),
                'mimeType' => $part->getMediaType().'/'.$part->getMediaSubtype(),
            ];
        }

        return $result;
    }

    /**
     * @param resource|string|null $body
     */
    private function stringifyBody(mixed $body): ?string
    {
        if (null === $body) {
            return null;
        }

        if (is_string($body)) {
            return $body;
        }

        if (is_resource($body)) {
            $contents = stream_get_contents($body);
            if (false === $contents) {
                return null;
            }
            // Rewind so the transport can still read it.
            if (false !== ftell($body)) {
                rewind($body);
            }

            return $contents;
        }

        return null;
    }
}
