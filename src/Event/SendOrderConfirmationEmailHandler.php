<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\OrderRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsMessageHandler]
final readonly class SendOrderConfirmationEmailHandler
{
    public function __construct(
        private OrderRepository $orderRepository,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(OrderCreated $event): void
    {
        $order = $this->orderRepository->get($event->orderId);
        $user = $order->user;
        $storage = $order->storage;
        $storageType = $storage->storageType;
        $place = $storageType->place;

        $portalUrl = $this->urlGenerator->generate(
            'portal_dashboard',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajné Sklady'))
            ->to(new Address($user->email, $user->fullName))
            ->subject('Potvrzení objednávky - ' . $place->name)
            ->htmlTemplate('email/order_confirmation.html.twig')
            ->context([
                'name' => $user->fullName,
                'orderNumber' => substr($order->id->toRfc4122(), 0, 8),
                'placeName' => $place->name,
                'placeAddress' => sprintf('%s, %s %s', $place->address, $place->postalCode, $place->city),
                'storageType' => $storageType->name,
                'storageNumber' => $storage->number,
                'startDate' => $order->startDate->format('d.m.Y'),
                'endDate' => $order->endDate?->format('d.m.Y') ?? 'Na dobu neurčitou',
                'totalPrice' => number_format($order->getTotalPriceInCzk(), 2, ',', ' ') . ' Kč',
                'expiresAt' => $order->expiresAt->format('d.m.Y H:i'),
                'portalUrl' => $portalUrl,
            ]);

        $this->mailer->send($email);
    }
}
