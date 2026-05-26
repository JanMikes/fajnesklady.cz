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
final readonly class SendTerminationNoticeAdminEmailHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private UserRepository $userRepository,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(TerminationNoticeRequested $event): void
    {
        $contract = $this->contractRepository->get($event->contractId);
        $tenant = $contract->user;
        $storage = $contract->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        $admins = $this->userRepository->findByRole(UserRole::ADMIN);

        foreach ($admins as $admin) {
            $email = (new TemplatedEmail())
                ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
                ->to(new Address($admin->email, $admin->fullName))
                ->subject(sprintf('Zákazník podal výpověď – %s', $tenant->fullName))
                ->htmlTemplate('email/termination_notice_admin.html.twig')
                ->context([
                    'adminName' => $admin->fullName,
                    'tenantName' => $tenant->fullName,
                    'tenantEmail' => $tenant->email,
                    'placeName' => $place->name,
                    'storageType' => $storageType->name,
                    'storageNumber' => $storage->number,
                    'terminatesAt' => $event->terminatesAt,
                ]);

            try {
                $this->mailer->send($email);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to send termination notice admin email', [
                    'contract_id' => $event->contractId->toRfc4122(),
                    'admin_email' => $admin->email,
                    'exception' => $e,
                ]);
            }
        }
    }
}
