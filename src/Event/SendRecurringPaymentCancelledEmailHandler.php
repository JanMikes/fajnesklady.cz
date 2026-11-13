<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\ContractRepository;
use App\Service\Billing\CardPaymentStopSummaryFactory;
use App\Service\OrderStatusUrlGenerator;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

#[AsMessageHandler]
final readonly class SendRecurringPaymentCancelledEmailHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private CardPaymentStopSummaryFactory $summaryFactory,
        private OrderStatusUrlGenerator $statusUrlGenerator,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RecurringPaymentCancelled $event): void
    {
        $contract = $this->contractRepository->get($event->contractId);
        $user = $contract->user;
        $storage = $contract->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();
        $summary = $this->summaryFactory->create($contract, $this->clock->now());

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to(new Address($user->email, $user->fullName))
            ->subject('Platba kartou zrušena, nájem pokračuje - Fajnesklady.cz')
            ->htmlTemplate('email/recurring_payment_cancelled.html.twig')
            ->context([
                'name' => $user->fullName,
                'placeName' => $place->name,
                'storageType' => $storageType->name,
                'storageNumber' => $storage->number,
                'summary' => $summary,
                'statusUrl' => $this->statusUrlGenerator->generate($contract->order),
            ]);

        $email->getHeaders()->addTextHeader('X-Order-Id', $contract->order->id->toRfc4122());

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send recurring payment cancelled email to tenant', [
                'contract_id' => $event->contractId->toRfc4122(),
                'exception' => $e,
            ]);
        }
    }
}
