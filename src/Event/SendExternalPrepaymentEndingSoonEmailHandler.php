<?php

declare(strict_types=1);

namespace App\Event;

use App\Enum\UserRole;
use App\Repository\ContractRepository;
use App\Repository\UserRepository;
use App\Service\OrderStatusUrlGenerator;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

/**
 * Sends the customer (and admin courtesy CC) an advance e-mail 7 days before
 * an externally-prepaid contract runs out. Asks the customer to contact
 * admin so a GoPay token can be set up — there is no self-service flow yet
 * (deferred to spec 026).
 *
 * Free contracts are skipped: there is nothing for the customer to do, no
 * future charge to announce.
 *
 * Uses Contract.lastAdvanceNoticeSentAt for daily idempotency, mirroring
 * SendRecurringPaymentAdvanceNoticeEmailHandler.
 */
#[AsMessageHandler]
final readonly class SendExternalPrepaymentEndingSoonEmailHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private UserRepository $userRepository,
        private MailerInterface $mailer,
        private OrderStatusUrlGenerator $statusUrlGenerator,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExternalPrepaymentEndingSoon $event): void
    {
        $contract = $this->contractRepository->get($event->contractId);

        if ($contract->isFree()) {
            return;
        }

        $user = $contract->user;
        $storage = $contract->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        $monthlyAmountInCzk = $contract->getEffectiveMonthlyAmount() / 100;

        $context = [
            'name' => $user->fullName,
            'placeName' => $place->name,
            'storageType' => $storageType->name,
            'storageNumber' => $storage->number,
            'paidThroughDate' => $contract->paidThroughDate?->format('d.m.Y'),
            'monthlyAmount' => number_format($monthlyAmountInCzk, 2, ',', ' '),
            'statusUrl' => $this->statusUrlGenerator->generate($contract->order),
        ];

        $customerEmail = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to(new Address($user->email, $user->fullName))
            ->subject('Vaše předplatné brzy končí — nastavte automatickou platbu')
            ->htmlTemplate('email/external_prepayment_ending_soon.html.twig')
            ->context($context);

        $sent = false;

        try {
            $this->mailer->send($customerEmail);
            $sent = true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send external prepayment ending notice to customer', [
                'contract_id' => $event->contractId->toRfc4122(),
                'exception' => $e,
            ]);
        }

        $this->notifyAdmins($contract, $context);

        if ($sent) {
            $contract->recordAdvanceNoticeSent($this->clock->now());
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function notifyAdmins(\App\Entity\Contract $contract, array $context): void
    {
        $admins = $this->userRepository->findByRole(UserRole::ADMIN);

        if (0 === count($admins)) {
            return;
        }

        $user = $contract->user;
        $adminContext = $context + [
            'userName' => $user->fullName,
            'userEmail' => $user->email,
        ];

        foreach ($admins as $admin) {
            $email = (new TemplatedEmail())
                ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
                ->to(new Address($admin->email, $admin->fullName))
                ->subject(sprintf('Externí předplatné brzy končí — %s', $user->fullName))
                ->htmlTemplate('email/external_prepayment_ending_soon_admin.html.twig')
                ->context($adminContext + ['adminName' => $admin->fullName]);

            try {
                $this->mailer->send($email);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to send external prepayment ending notice to admin', [
                    'contract_id' => $contract->id->toRfc4122(),
                    'admin_email' => $admin->email,
                    'exception' => $e,
                ]);
            }
        }
    }
}
