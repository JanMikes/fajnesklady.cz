<?php

declare(strict_types=1);

namespace App\Event;

use App\Enum\RentalType;
use App\Repository\ContractRepository;
use App\Service\Order\OrderReferenceFormatter;
use App\Service\OrderStatusUrlGenerator;
use App\Service\Place\PlaceAddressFormatter;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

#[AsMessageHandler]
final readonly class SendContractExpiringReminderHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private MailerInterface $mailer,
        private OrderStatusUrlGenerator $statusUrlGenerator,
        private PlaceAddressFormatter $addressFormatter,
        private OrderReferenceFormatter $orderReferenceFormatter,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ContractExpiringSoon $event): void
    {
        $contract = $this->contractRepository->get($event->contractId);
        $user = $contract->user;
        $storage = $contract->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        $statusUrl = $this->statusUrlGenerator->generate($contract->order);

        // Signed link — the renewal page prefills the customer's PII, so it
        // must not be reachable by guessing an order id (see OrderRenewController).
        $renewalUrl = $this->statusUrlGenerator->generateRenewal($contract->order);

        $subject = 1 === $event->daysRemaining
            ? 'Zítra končí Vaše smlouva - '.$place->name
            : sprintf('Vaše smlouva končí za %d dní - %s', $event->daysRemaining, $place->name);

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to(new Address($user->email, $user->fullName))
            ->subject($subject)
            ->htmlTemplate('email/contract_expiring.html.twig')
            ->context([
                'name' => $user->fullName,
                'contractNumber' => $this->orderReferenceFormatter->format($contract->order),
                'placeName' => $place->name,
                'placeAddress' => $this->addressFormatter->format($place),
                'placeNavigationUrl' => $this->addressFormatter->navigationUrl($place),
                'storageType' => $storageType->name,
                'storageNumber' => $storage->number,
                'endDate' => $contract->endDate?->format('d.m.Y'),
                'daysRemaining' => $event->daysRemaining,
                'statusUrl' => $statusUrl,
                'renewalUrl' => $renewalUrl,
                'isLimited' => RentalType::LIMITED === $contract->rentalType,
            ]);

        $email->getHeaders()->addTextHeader('X-Order-Id', $contract->order->id->toRfc4122());

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send contract expiring reminder email', [
                'contract_id' => $event->contractId->toRfc4122(),
                'exception' => $e,
            ]);
        }
    }
}
