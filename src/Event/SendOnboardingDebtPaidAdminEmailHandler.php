<?php

declare(strict_types=1);

namespace App\Event;

use App\Enum\UserRole;
use App\Repository\OrderRepository;
use App\Repository\UserRepository;
use App\Service\Order\OrderReferenceFormatter;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

/**
 * Operator heads-up that an onboarding debt was paid. Especially useful for
 * bank-transfer debts, which clear asynchronously via the FIO cron with no
 * other push signal.
 */
#[AsMessageHandler]
final readonly class SendOnboardingDebtPaidAdminEmailHandler
{
    public function __construct(
        private OrderRepository $orderRepository,
        private UserRepository $userRepository,
        private OrderReferenceFormatter $orderReferenceFormatter,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(OnboardingDebtPaid $event): void
    {
        $admins = $this->userRepository->findByRole(UserRole::ADMIN);

        if (0 === count($admins)) {
            return;
        }

        $order = $this->orderRepository->get($event->orderId);
        $customer = $order->user;
        $storage = $order->storage;

        $context = [
            'customerName' => $customer->fullName,
            'customerEmail' => $customer->email,
            'amountCzk' => number_format($event->amountInHaler / 100, 0, ',', ' '),
            'paidAt' => $order->debtPaidAt,
            'placeName' => $storage->getPlace()->name,
            'storageLabel' => sprintf('%s č. %s', $storage->storageType->name, $storage->number),
            'orderReference' => $this->orderReferenceFormatter->format($order),
        ];

        foreach ($admins as $admin) {
            $email = (new TemplatedEmail())
                ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
                ->to(new Address($admin->email, $admin->fullName))
                ->subject(sprintf('Dluh uhrazen — %s (%s Kč)', $customer->fullName, $context['amountCzk']))
                ->htmlTemplate('email/debt_paid_admin.html.twig')
                ->context($context + ['adminName' => $admin->fullName]);

            $email->getHeaders()->addTextHeader('X-Order-Id', $order->id->toRfc4122());

            try {
                $this->mailer->send($email);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to send debt paid alert to admin', [
                    'order_id' => $order->id->toRfc4122(),
                    'admin_email' => $admin->email,
                    'exception' => $e,
                ]);
            }
        }
    }
}
