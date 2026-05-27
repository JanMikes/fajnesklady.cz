<?php

declare(strict_types=1);

namespace App\Event;

use App\Enum\UserRole;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

#[AsMessageHandler]
final readonly class SendBankTransferMismatchAdminEmailHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private OrderRepository $orderRepository,
        private UserRepository $userRepository,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(BankTransferAmountMismatch $event): void
    {
        $admins = $this->userRepository->findByRole(UserRole::ADMIN);

        if (0 === count($admins)) {
            return;
        }

        $context = $this->buildContext($event);

        foreach ($admins as $admin) {
            $email = (new TemplatedEmail())
                ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
                ->to(new Address($admin->email, $admin->fullName))
                ->subject(sprintf('Neshoda částky bankovního převodu — VS %s', $event->variableSymbol ?? 'N/A'))
                ->htmlTemplate('email/bank_transfer_amount_mismatch_admin.html.twig')
                ->context($context + ['adminName' => $admin->fullName]);

            if (null !== $event->orderId) {
                $email->getHeaders()->addTextHeader('X-Order-Id', $event->orderId->toRfc4122());
            }

            try {
                $this->mailer->send($email);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to send bank transfer mismatch alert to admin', [
                    'bank_transaction_id' => $event->bankTransactionId->toRfc4122(),
                    'admin_email' => $admin->email,
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(BankTransferAmountMismatch $event): array
    {
        $expectedInCzk = number_format($event->expectedAmount / 100, 2, ',', ' ');
        $receivedInCzk = number_format($event->receivedAmount / 100, 2, ',', ' ');
        $differenceInCzk = number_format(($event->receivedAmount - $event->expectedAmount) / 100, 2, ',', ' ');

        $context = [
            'bankTransactionId' => $event->bankTransactionId->toRfc4122(),
            'variableSymbol' => $event->variableSymbol,
            'expectedAmount' => $expectedInCzk,
            'receivedAmount' => $receivedInCzk,
            'differenceAmount' => $differenceInCzk,
            'subjectLabel' => null,
            'subjectValue' => null,
            'customerName' => null,
            'customerEmail' => null,
        ];

        if (null !== $event->contractId) {
            $contract = $this->contractRepository->get($event->contractId);
            $context['subjectLabel'] = 'Smlouva (ID)';
            $context['subjectValue'] = $event->contractId->toRfc4122();
            $context['customerName'] = $contract->user->fullName;
            $context['customerEmail'] = $contract->user->email;

            return $context;
        }

        \assert(null !== $event->orderId);
        $order = $this->orderRepository->get($event->orderId);
        $context['subjectLabel'] = 'Objednávka (ID)';
        $context['subjectValue'] = $event->orderId->toRfc4122();
        $context['customerName'] = $order->user->fullName;
        $context['customerEmail'] = $order->user->email;

        return $context;
    }
}
