<?php

declare(strict_types=1);

namespace App\Event;

use App\Enum\UserRole;
use App\Repository\ContractRepository;
use App\Repository\UserRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

#[AsMessageHandler]
final readonly class SendPaymentDefaultEmailHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private UserRepository $userRepository,
        private MailerInterface $mailer,
    ) {
    }

    public function __invoke(ContractTerminatedDueToPaymentFailure $event): void
    {
        $contract = $this->contractRepository->get($event->contractId);
        $tenant = $contract->user;
        $storage = $contract->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();
        $debtCzk = $event->outstandingDebtAmount / 100;

        // Email to tenant
        $tenantEmail = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajné Sklady'))
            ->to(new Address($tenant->email, $tenant->fullName))
            ->subject('Smlouva ukončena z důvodu neuhrazení platby - Fajné Sklady')
            ->htmlTemplate('email/payment_default_tenant.html.twig')
            ->context([
                'name' => $tenant->fullName,
                'placeName' => $place->name,
                'storageType' => $storageType->name,
                'storageNumber' => $storage->number,
                'outstandingDebt' => $debtCzk,
                'hasDebt' => $event->outstandingDebtAmount > 0,
            ]);

        $this->mailer->send($tenantEmail);

        // Email to all admins
        $admins = $this->userRepository->findByRole(UserRole::ADMIN);

        foreach ($admins as $admin) {
            $adminEmail = (new TemplatedEmail())
                ->from(new Address('noreply@fajnesklady.cz', 'Fajné Sklady'))
                ->to(new Address($admin->email, $admin->fullName))
                ->subject(sprintf('DLUH: Smlouva ukončena - %s, dluh %.2f Kč', $tenant->fullName, $debtCzk))
                ->htmlTemplate('email/payment_default_admin.html.twig')
                ->context([
                    'adminName' => $admin->fullName,
                    'tenantName' => $tenant->fullName,
                    'tenantEmail' => $tenant->email,
                    'tenantPhone' => $tenant->phone,
                    'placeName' => $place->name,
                    'storageType' => $storageType->name,
                    'storageNumber' => $storage->number,
                    'outstandingDebt' => $debtCzk,
                    'hasDebt' => $event->outstandingDebtAmount > 0,
                    'paidThroughDate' => $contract->paidThroughDate,
                    'terminatedAt' => $contract->terminatedAt,
                ]);

            $this->mailer->send($adminEmail);
        }
    }
}
