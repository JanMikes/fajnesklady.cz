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
final readonly class SendRecurringPaymentFailedAdminEmailHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private UserRepository $userRepository,
        private MailerInterface $mailer,
    ) {
    }

    public function __invoke(RecurringPaymentFailed $event): void
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
                ->subject(sprintf('UPOZORNĚNÍ: Neúspěšná platba - %s (pokus %d)', $user->fullName, $event->attempt))
                ->htmlTemplate('email/recurring_payment_failed_admin.html.twig')
                ->context([
                    'adminName' => $admin->fullName,
                    'userName' => $user->fullName,
                    'userEmail' => $user->email,
                    'placeName' => $place->name,
                    'storageType' => $storageType->name,
                    'storageNumber' => $storage->number,
                    'attempt' => $event->attempt,
                    'reason' => $event->reason,
                ]);

            $this->mailer->send($email);
        }
    }
}
