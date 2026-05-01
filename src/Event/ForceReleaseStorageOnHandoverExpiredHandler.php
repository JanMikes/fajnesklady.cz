<?php

declare(strict_types=1);

namespace App\Event;

use App\Enum\UserRole;
use App\Repository\ContractRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

#[AsMessageHandler]
final readonly class ForceReleaseStorageOnHandoverExpiredHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private UserRepository $userRepository,
        private MailerInterface $mailer,
        private AuditLogger $auditLogger,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(HandoverExpired $event): void
    {
        $contract = $this->contractRepository->get($event->contractId);
        $storage = $contract->storage;
        $now = $this->clock->now();

        if ($storage->isOccupied()) {
            $storage->release($now);
            $this->auditLogger->logStorageReleased($storage, 'Force-released: handover protocol incomplete after 14 days');
        }

        $place = $storage->getPlace();
        $admins = $this->userRepository->findByRole(UserRole::ADMIN);

        foreach ($admins as $admin) {
            $email = (new TemplatedEmail())
                ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
                ->to(new Address($admin->email, $admin->fullName))
                ->subject('Předávací protokol nevyplněn - sklad uvolněn automaticky - '.$place->name)
                ->htmlTemplate('email/handover_expired_admin.html.twig')
                ->context([
                    'adminName' => $admin->fullName,
                    'placeName' => $place->name,
                    'storageNumber' => $storage->number,
                    'tenantName' => $contract->user->fullName,
                    'landlordName' => null !== $storage->owner ? $storage->owner->fullName : 'N/A',
                    'contractTerminatedAt' => $contract->terminatedAt?->format('d.m.Y'),
                ]);

            try {
                $this->mailer->send($email);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to send handover expired admin notification', [
                    'handover_protocol_id' => $event->handoverProtocolId->toRfc4122(),
                    'admin_email' => $admin->email,
                    'exception' => $e,
                ]);
            }
        }
    }
}
