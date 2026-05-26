<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\FineRepository;
use App\Service\Fine\FinePaymentUrlGenerator;
use App\Service\Payment\QrPaymentGenerator;
use App\Service\Place\PlaceAddressFormatter;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

#[AsMessageHandler]
final readonly class SendFinePaymentReminderEmailHandler
{
    public function __construct(
        private FineRepository $fineRepository,
        private FinePaymentUrlGenerator $paymentUrlGenerator,
        private QrPaymentGenerator $qrPaymentGenerator,
        private PlaceAddressFormatter $addressFormatter,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(FinePaymentReminderRequested $event): void
    {
        $fine = $this->fineRepository->findById($event->fineId);
        if (null === $fine) {
            return;
        }

        if (!$fine->isPayable()) {
            return;
        }

        $user = $fine->user;
        $place = $fine->contract->storage->getPlace();
        $paymentUrl = $this->paymentUrlGenerator->generatePaymentUrl($fine);

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to(new Address($user->email, $user->fullName))
            ->subject(sprintf('Připomínka nezaplacené pokuty — %s — Fajnesklady.cz', $fine->type->label()))
            ->htmlTemplate('email/fine_payment_reminder.html.twig')
            ->context([
                'name' => $user->fullName,
                'fineType' => $fine->type->label(),
                'amountCzk' => number_format($fine->getAmountInCzk(), 0, ',', ' '),
                'description' => $fine->description,
                'placeName' => $place->name,
                'placeAddress' => $this->addressFormatter->format($place),
                'paymentUrl' => $paymentUrl,
                'bankAccount' => $this->qrPaymentGenerator->getBankAccountFormatted(),
                'variableSymbol' => $fine->variableSymbol,
                'stage' => $event->stage,
            ]);

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send fine payment reminder email', [
                'fine_id' => $fine->id->toRfc4122(),
                'stage' => $event->stage,
                'exception' => $e,
            ]);
        }
    }
}
