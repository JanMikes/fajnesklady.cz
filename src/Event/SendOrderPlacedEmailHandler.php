<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\OrderRepository;
use App\Service\OrderEmailAttachments;
use App\Service\OrderStatusUrlGenerator;
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
    ) {
    }

    public function __invoke(OrderPlaced $event): void
    {
        $order = $this->orderRepository->get($event->orderId);
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
                'placeAddress' => sprintf('%s, %s %s', $place->address, $place->postalCode, $place->city),
                'storageType' => $storageType->name,
                'storageNumber' => $storage->number,
                'startDate' => $order->startDate->format('d.m.Y'),
                'endDate' => $order->endDate?->format('d.m.Y') ?? 'Na dobu neurčitou',
                'priceCzk' => $order->getFirstPaymentPriceInCzk(),
                'isRecurring' => $order->isRecurring(),
                'expiresAt' => $order->expiresAt->format('d.m.Y H:i'),
                'lockCode' => $storage->lockCode,
                'statusUrl' => $statusUrl,
            ]);

        $this->attachments->attachLegalDocuments($email, $order);

        $this->mailer->send($email);
    }
}
