<?php

declare(strict_types=1);

namespace App\Event;

use App\Enum\UserRole;
use App\Repository\ContractRepository;
use App\Repository\ManualPaymentRequestRepository;
use App\Repository\UserRepository;
use App\Service\Billing\ManualBillingReminderSchedule;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

#[AsMessageHandler]
final readonly class SendManualBillingOverdueAdminEmailHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private ManualPaymentRequestRepository $manualPaymentRequestRepository,
        private UserRepository $userRepository,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ManualBillingPaymentOverdue $event): void
    {
        $admins = $this->userRepository->findByRole(UserRole::ADMIN);

        if (0 === count($admins)) {
            return;
        }

        $contract = $this->contractRepository->get($event->contractId);
        $request = $this->manualPaymentRequestRepository->get($event->manualPaymentRequestId);
        $user = $contract->user;
        $storage = $contract->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        $stageLabel = match ($event->stage) {
            ManualBillingReminderSchedule::STAGE_OVERDUE_FIRST => '3 dny po splatnosti',
            ManualBillingReminderSchedule::STAGE_OVERDUE_FINAL => '7 dní po splatnosti',
            default => $event->stage,
        };

        foreach ($admins as $admin) {
            $email = (new TemplatedEmail())
                ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
                ->to(new Address($admin->email, $admin->fullName))
                ->subject(sprintf('UPOZORNĚNÍ: Ručně schvalovaná platba %s — %s', $stageLabel, $user->fullName))
                ->htmlTemplate('email/manual_billing_overdue_admin.html.twig')
                ->context([
                    'adminName' => $admin->fullName,
                    'userName' => $user->fullName,
                    'userEmail' => $user->email,
                    'placeName' => $place->name,
                    'storageType' => $storageType->name,
                    'storageNumber' => $storage->number,
                    'amountInCzk' => number_format($request->amount / 100, 2, ',', ' '),
                    'periodStart' => $request->periodStart,
                    'stageLabel' => $stageLabel,
                ]);

            $email->getHeaders()->addTextHeader('X-Order-Id', $contract->order->id->toRfc4122());

            try {
                $this->mailer->send($email);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to send manual-billing overdue admin email', [
                    'contract_id' => $event->contractId->toRfc4122(),
                    'admin_email' => $admin->email,
                    'exception' => $e,
                ]);
            }
        }
    }
}
