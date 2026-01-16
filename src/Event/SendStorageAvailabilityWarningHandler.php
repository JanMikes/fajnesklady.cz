<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Contract;
use App\Enum\UserRole;
use App\Repository\OrderRepository;
use App\Repository\UserRepository;
use App\Service\AtRiskContractChecker;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsMessageHandler]
final readonly class SendStorageAvailabilityWarningHandler
{
    public function __construct(
        private OrderRepository $orderRepository,
        private UserRepository $userRepository,
        private AtRiskContractChecker $atRiskContractChecker,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(OrderCreated $event): void
    {
        $order = $this->orderRepository->get($event->orderId);
        $storage = $order->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();
        $now = $this->clock->now();

        $atRiskContracts = $this->atRiskContractChecker->findAtRiskContracts($storageType, $now);

        if (0 === count($atRiskContracts)) {
            return;
        }

        $notifiedUsers = [];

        foreach ($atRiskContracts as $contract) {
            $this->sendUserWarningEmail($contract);
            $notifiedUsers[] = [
                'userName' => $contract->user->fullName,
                'userEmail' => $contract->user->email,
                'storageNumber' => $contract->storage->number,
                'contractEndDate' => $contract->endDate?->format('d.m.Y'),
            ];
        }

        $this->sendAdminSummaryEmail($storageType, $place, $notifiedUsers);
    }

    private function sendUserWarningEmail(Contract $contract): void
    {
        $user = $contract->user;
        $storage = $contract->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        $portalUrl = $this->urlGenerator->generate(
            'portal_dashboard',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajné Sklady'))
            ->to(new Address($user->email, $user->fullName))
            ->subject(sprintf('Upozornění - Váš typ skladu je žádaný - %s', $place->name))
            ->htmlTemplate('email/storage_availability_warning.html.twig')
            ->context([
                'name' => $user->fullName,
                'placeName' => $place->name,
                'placeAddress' => sprintf('%s, %s %s', $place->address, $place->postalCode, $place->city),
                'storageTypeName' => $storageType->name,
                'storageNumber' => $storage->number,
                'endDate' => $contract->endDate?->format('d.m.Y'),
                'portalUrl' => $portalUrl,
            ]);

        $this->mailer->send($email);
    }

    /**
     * @param array<array{userName: string, userEmail: string, storageNumber: string, contractEndDate: string|null}> $notifiedUsers
     */
    private function sendAdminSummaryEmail(\App\Entity\StorageType $storageType, \App\Entity\Place $place, array $notifiedUsers): void
    {
        $admins = $this->userRepository->findByRole(UserRole::ADMIN);

        if (0 === count($admins)) {
            return;
        }

        $notificationCount = count($notifiedUsers);

        foreach ($admins as $admin) {
            $email = (new TemplatedEmail())
                ->from(new Address('noreply@fajnesklady.cz', 'Fajné Sklady'))
                ->to(new Address($admin->email, $admin->fullName))
                ->subject(sprintf('Oznámení o dostupnosti skladů - %d uživatelů upozorněno', $notificationCount))
                ->htmlTemplate('email/storage_availability_warning_admin.html.twig')
                ->context([
                    'adminName' => $admin->fullName,
                    'placeName' => $place->name,
                    'placeAddress' => sprintf('%s, %s %s', $place->address, $place->postalCode, $place->city),
                    'storageTypeName' => $storageType->name,
                    'notifiedUsers' => $notifiedUsers,
                    'notificationCount' => $notificationCount,
                ]);

            $this->mailer->send($email);
        }
    }
}
