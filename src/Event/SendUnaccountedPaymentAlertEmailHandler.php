<?php

declare(strict_types=1);

namespace App\Event;

use App\Enum\UserRole;
use App\Repository\OrderRepository;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

/**
 * Sends every admin a "⚠ Přijatá platba bez uplatnění" alert when GoPay
 * captured money that we cannot apply anywhere. Mirrors the admin-fanout
 * pattern of {@see SendAmountMismatchAlertEmailHandler}.
 */
#[AsMessageHandler]
final readonly class SendUnaccountedPaymentAlertEmailHandler
{
    private const array REASON_LABELS = [
        UnaccountedPaidPayment::REASON_ORDER_CANCELLED => 'Objednávka byla mezitím zrušena — zákazník zaplatil až po zrušení.',
        UnaccountedPaidPayment::REASON_ORDER_EXPIRED => 'Objednávka mezitím expirovala — zákazník zaplatil až po expiraci.',
        UnaccountedPaidPayment::REASON_UNKNOWN_PAYMENT => 'Platba neodpovídá žádné evidované objednávce ani platbě (pravděpodobně opuštěná starší platební relace zaplacená z druhého okna).',
        UnaccountedPaidPayment::REASON_UNKNOWN_RECURRING_PARENT => 'Opakovaná platba odkazuje na rodičovskou platbu, ke které neznáme žádnou smlouvu.',
        UnaccountedPaidPayment::REASON_CARD_SETUP_CONTRACT_TERMINATED => 'Platba za nastavení karty dorazila pro již ukončenou smlouvu.',
    ];

    public function __construct(
        private OrderRepository $orderRepository,
        private UserRepository $userRepository,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(UnaccountedPaidPayment $event): void
    {
        $admins = $this->userRepository->findByRole(UserRole::ADMIN);

        if (0 === count($admins)) {
            return;
        }

        $order = null !== $event->orderId ? $this->orderRepository->find($event->orderId) : null;

        $context = [
            'goPayPaymentId' => $event->goPayPaymentId,
            'reasonLabel' => self::REASON_LABELS[$event->reason] ?? $event->reason,
            'amount' => null !== $event->amount ? number_format($event->amount / 100, 2, ',', ' ') : null,
            'orderId' => $event->orderId?->toRfc4122(),
            'customerName' => $order?->user->fullName,
            'customerEmail' => $order?->user->email,
        ];

        foreach ($admins as $admin) {
            $email = (new TemplatedEmail())
                ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
                ->to(new Address($admin->email, $admin->fullName))
                ->subject(sprintf('⚠ Přijatá platba bez uplatnění — %s', $event->goPayPaymentId))
                ->htmlTemplate('email/unaccounted_paid_payment.html.twig')
                ->context($context + ['adminName' => $admin->fullName]);

            if (null !== $event->orderId) {
                $email->getHeaders()->addTextHeader('X-Order-Id', $event->orderId->toRfc4122());
            }

            try {
                $this->mailer->send($email);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to send unaccounted payment alert to admin', [
                    'gopay_payment_id' => $event->goPayPaymentId,
                    'admin_email' => $admin->email,
                    'exception' => $e,
                ]);
            }
        }
    }
}
