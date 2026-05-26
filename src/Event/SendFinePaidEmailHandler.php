<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\FineRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

#[AsMessageHandler]
final readonly class SendFinePaidEmailHandler
{
    public function __construct(
        private FineRepository $fineRepository,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(FinePaid $event): void
    {
        $fine = $this->fineRepository->findById($event->fineId);
        if (null === $fine) {
            return;
        }

        $user = $fine->user;

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to(new Address($user->email, $user->fullName))
            ->subject('Pokuta zaplacena — Fajnesklady.cz')
            ->htmlTemplate('email/fine_paid.html.twig')
            ->context([
                'name' => $user->fullName,
                'fineType' => $fine->type->label(),
                'amountCzk' => number_format($fine->getAmountInCzk(), 0, ',', ' '),
                'paidAt' => $fine->paidAt,
            ]);

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send fine paid email', [
                'fine_id' => $fine->id->toRfc4122(),
                'exception' => $e,
            ]);
        }
    }
}
