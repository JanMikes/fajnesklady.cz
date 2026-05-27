<?php

declare(strict_types=1);

namespace App\Event;

use App\Enum\UserRole;
use App\Repository\ContractRepository;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

#[AsMessageHandler]
final readonly class SendPaymentDemandAdminEmailHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private UserRepository $userRepository,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PaymentDemandSent $event): void
    {
        $contract = $this->contractRepository->get($event->contractId);
        $tenant = $contract->user;
        $storage = $contract->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();
        $monthlyAmount = $contract->getEffectiveMonthlyAmount() / 100;

        $admins = $this->userRepository->findByRole(UserRole::ADMIN);

        foreach ($admins as $admin) {
            $email = (new TemplatedEmail())
                ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
                ->to(new Address($admin->email, $admin->fullName))
                ->subject(sprintf('Výzva k úhradě odeslána – %s', $tenant->fullName))
                ->htmlTemplate('email/payment_demand_admin.html.twig')
                ->context([
                    'adminName' => $admin->fullName,
                    'tenantName' => $tenant->fullName,
                    'tenantEmail' => $tenant->email,
                    'placeName' => $place->name,
                    'storageType' => $storageType->name,
                    'storageNumber' => $storage->number,
                    'amount' => $monthlyAmount,
                    'deadline' => $event->deadline,
                ]);

            $email->getHeaders()->addTextHeader('X-Order-Id', $contract->order->id->toRfc4122());

            try {
                $this->mailer->send($email);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to send payment demand admin email', [
                    'contract_id' => $event->contractId->toRfc4122(),
                    'admin_email' => $admin->email,
                    'exception' => $e,
                ]);
            }
        }
    }
}
