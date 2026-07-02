<?php

declare(strict_types=1);

namespace App\Event;

use App\Enum\BillingMode;
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
final readonly class SendContractProlongedEmailHandler
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

    public function __invoke(ContractProlonged $event): void
    {
        $contract = $this->contractRepository->get($event->contractId);
        $user = $contract->user;
        $storage = $contract->storage;
        $place = $storage->getPlace();

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to(new Address($user->email, $user->fullName))
            ->subject(sprintf('Smlouva prodloužena do %s - %s', $event->newEndDate->format('d.m.Y'), $place->name))
            ->htmlTemplate('email/contract_prolonged.html.twig')
            ->context([
                'name' => $user->fullName,
                'contractNumber' => $this->orderReferenceFormatter->format($contract->order),
                'placeName' => $place->name,
                'placeAddress' => $this->addressFormatter->format($place),
                'storageType' => $storage->storageType->name,
                'storageNumber' => $storage->number,
                'newEndDate' => $event->newEndDate,
                'isAutoRecurring' => BillingMode::AUTO_RECURRING === $contract->billingMode,
                'isFree' => $contract->isFree(),
                'nextBillingDate' => $contract->nextBillingDate,
                'statusUrl' => $this->statusUrlGenerator->generate($contract->order),
            ]);

        $email->getHeaders()->addTextHeader('X-Order-Id', $contract->order->id->toRfc4122());

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send contract prolonged email', [
                'contract_id' => $event->contractId->toRfc4122(),
                'exception' => $e,
            ]);
        }
    }
}
