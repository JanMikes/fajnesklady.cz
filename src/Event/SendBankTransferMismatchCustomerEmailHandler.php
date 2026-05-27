<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

#[AsMessageHandler]
final readonly class SendBankTransferMismatchCustomerEmailHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private OrderRepository $orderRepository,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(BankTransferAmountMismatch $event): void
    {
        if (null !== $event->contractId) {
            $order = $this->contractRepository->get($event->contractId)->order;
        } else {
            \assert(null !== $event->orderId);
            $order = $this->orderRepository->get($event->orderId);
        }

        $user = $order->user;

        $expectedInCzk = number_format($event->expectedAmount / 100, 2, ',', ' ');
        $receivedInCzk = number_format($event->receivedAmount / 100, 2, ',', ' ');
        $differenceInCzk = number_format(abs($event->receivedAmount - $event->expectedAmount) / 100, 2, ',', ' ');
        $isOverpaid = $event->receivedAmount > $event->expectedAmount;

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to(new Address($user->email, $user->fullName))
            ->subject('Neshoda částky platby — Fajnesklady.cz')
            ->htmlTemplate('email/bank_transfer_amount_mismatch_customer.html.twig')
            ->context([
                'customerName' => $user->fullName,
                'expectedAmount' => $expectedInCzk,
                'receivedAmount' => $receivedInCzk,
                'differenceAmount' => $differenceInCzk,
                'isOverpaid' => $isOverpaid,
                'variableSymbol' => $event->variableSymbol,
            ]);

        $email->getHeaders()->addTextHeader('X-Order-Id', $order->id->toRfc4122());

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send bank transfer mismatch notification to customer', [
                'bank_transaction_id' => $event->bankTransactionId->toRfc4122(),
                'customer_email' => $user->email,
                'exception' => $e,
            ]);
        }
    }
}
