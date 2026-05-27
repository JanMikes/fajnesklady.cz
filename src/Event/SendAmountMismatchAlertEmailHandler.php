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

/**
 * Sends every admin a "⚠ Neshoda částky platby" alert when GoPay reports a
 * charge with an amount different from what we expected. Mirrors the admin-fanout
 * pattern of {@see SendExternalPrepaymentEndingSoonEmailHandler::notifyAdmins()}.
 *
 * Admin must know about every mismatch — even ones that turn out to be the
 * legitimate prorated tail of a fixed-term contract. The body suggests verifying
 * with GoPay and the customer; the call between "this is fine" and "refund/fix"
 * is a human judgement we don't try to automate.
 */
#[AsMessageHandler]
final readonly class SendAmountMismatchAlertEmailHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private OrderRepository $orderRepository,
        private UserRepository $userRepository,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PaymentAmountMismatch $event): void
    {
        $admins = $this->userRepository->findByRole(UserRole::ADMIN);

        if (0 === count($admins)) {
            return;
        }

        $context = $this->buildContext($event);
        $orderId = $event->orderId ?? (null !== $event->contractId ? $this->contractRepository->get($event->contractId)->order->id : null);

        foreach ($admins as $admin) {
            $email = (new TemplatedEmail())
                ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
                ->to(new Address($admin->email, $admin->fullName))
                ->subject(sprintf('⚠ Neshoda částky platby — %s', $event->goPayPaymentId))
                ->htmlTemplate('email/payment_amount_mismatch.html.twig')
                ->context($context + ['adminName' => $admin->fullName]);

            if (null !== $orderId) {
                $email->getHeaders()->addTextHeader('X-Order-Id', $orderId->toRfc4122());
            }

            try {
                $this->mailer->send($email);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to send payment amount mismatch alert to admin', [
                    'gopay_payment_id' => $event->goPayPaymentId,
                    'admin_email' => $admin->email,
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(PaymentAmountMismatch $event): array
    {
        $expectedInCzk = number_format($event->expectedAmount / 100, 2, ',', ' ');
        $receivedInCzk = number_format($event->receivedAmount / 100, 2, ',', ' ');
        $differenceInCzk = number_format(($event->receivedAmount - $event->expectedAmount) / 100, 2, ',', ' ');

        $context = [
            'goPayPaymentId' => $event->goPayPaymentId,
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

        if (null !== $event->orderId) {
            $order = $this->orderRepository->get($event->orderId);
            $context['subjectLabel'] = 'Objednávka (ID)';
            $context['subjectValue'] = $event->orderId->toRfc4122();
            $context['customerName'] = $order->user->fullName;
            $context['customerEmail'] = $order->user->email;
        }

        return $context;
    }
}
