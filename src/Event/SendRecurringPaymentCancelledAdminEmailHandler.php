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
final readonly class SendRecurringPaymentCancelledAdminEmailHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private UserRepository $userRepository,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RecurringPaymentCancelled $event): void
    {
        $admins = $this->userRepository->findByRole(UserRole::ADMIN);

        if (0 === count($admins)) {
            return;
        }

        $contract = $this->contractRepository->get($event->contractId);
        $user = $contract->user;
        $storage = $contract->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        foreach ($admins as $admin) {
            $email = (new TemplatedEmail())
                ->from(new Address('noreply@fajnesklady.cz', 'Fajné Sklady'))
                ->to(new Address($admin->email, $admin->fullName))
                ->subject(sprintf('Pravidelná platba zrušena - %s', $user->fullName))
                ->htmlTemplate('email/recurring_payment_cancelled_admin.html.twig')
                ->context([
                    'adminName' => $admin->fullName,
                    'userName' => $user->fullName,
                    'userEmail' => $user->email,
                    'placeName' => $place->name,
                    'storageType' => $storageType->name,
                    'storageNumber' => $storage->number,
                ]);

            try {
                $this->mailer->send($email);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to send recurring payment cancelled email to admin', [
                    'contract_id' => $event->contractId->toRfc4122(),
                    'admin_email' => $admin->email,
                    'exception' => $e,
                ]);
            }
        }
    }
}
