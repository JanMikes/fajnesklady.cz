<?php

declare(strict_types=1);

namespace App\Event;

use App\Enum\OrderStatus;
use App\Repository\OrderRepository;
use App\Service\OrderEmailAttachments;
use App\Service\OrderStatusUrlGenerator;
use App\Service\Place\PlaceAddressFormatter;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

#[AsMessageHandler]
final readonly class SendOrderPlacedEmailHandler
{
    public function __construct(
        private OrderRepository $orderRepository,
        private MailerInterface $mailer,
        private OrderStatusUrlGenerator $statusUrlGenerator,
        private OrderEmailAttachments $attachments,
        private PlaceAddressFormatter $addressFormatter,
    ) {
    }

    public function __invoke(OrderPlaced $event): void
    {
        $order = $this->orderRepository->get($event->orderId);

        // Admin onboardings that complete in a single transaction (migrate;
        // digital EXTERNAL/prepaid; digital free) queue OrderPlaced → OrderPaid
        // → OrderCompleted together. By the time this handler runs post-commit,
        // the order is already COMPLETED and SendRentalActivatedEmailHandler is
        // about to send the richer e-mail. Suppressing this one avoids a
        // near-duplicate "Potvrzení objednávky" with a misleading "rezervace
        // platná do" warning landing seconds before "Pronájem zahájen".
        if (true === $order->isAdminCreated && OrderStatus::COMPLETED === $order->status) {
            return;
        }

        $user = $order->user;
        $storage = $order->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        $statusUrl = $this->statusUrlGenerator->generate($order);

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to(new Address($user->email, $user->fullName))
            ->subject('Potvrzení objednávky - '.$place->name)
            ->htmlTemplate('email/order_placed.html.twig')
            ->context([
                'name' => $user->fullName,
                'orderNumber' => substr($order->id->toRfc4122(), 0, 8),
                'placeName' => $place->name,
                'placeAddress' => $this->addressFormatter->format($place),
                'placeNavigationUrl' => $this->addressFormatter->navigationUrl($place),
                'storageType' => $storageType->name,
                'storageNumber' => $storage->number,
                'startDate' => $order->startDate->format('d.m.Y'),
                'endDate' => $order->endDate?->format('d.m.Y') ?? 'Na dobu neurčitou',
                'priceCzk' => $order->getFirstPaymentPriceInCzk(),
                'isRecurring' => $order->isRecurring(),
                'expiresAt' => $order->expiresAt->format('d.m.Y H:i'),
                'statusUrl' => $statusUrl,
            ]);

        $this->attachments->attachLegalDocuments($email, $order);

        $email->getHeaders()->addTextHeader('X-Order-Id', $order->id->toRfc4122());

        $this->mailer->send($email);
    }
}
