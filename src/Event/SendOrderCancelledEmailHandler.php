<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\OrderRepository;
use App\Service\OrderStatusUrlGenerator;
use App\Service\Place\PlaceAddressFormatter;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

#[AsMessageHandler]
final readonly class SendOrderCancelledEmailHandler
{
    public function __construct(
        private OrderRepository $orderRepository,
        private MailerInterface $mailer,
        private OrderStatusUrlGenerator $statusUrlGenerator,
        private PlaceAddressFormatter $addressFormatter,
    ) {
    }

    public function __invoke(OrderCancelled $event): void
    {
        $order = $this->orderRepository->get($event->orderId);
        $user = $order->user;
        $storage = $order->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to(new Address($user->email, $user->fullName))
            ->subject('Objednávka zrušena - '.$place->name)
            ->htmlTemplate('email/order_cancelled.html.twig')
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
                'isUpfrontTranches' => $order->isPaidInUpfrontTranches(),
                // For YEARLY orders firstPaymentPrice is the per-year figure —
                // the price partial needs it to avoid labelling it "/ měsíc".
                'yearlyAmountCzk' => $order->isYearlyFrequency() ? $order->getFirstPaymentPriceInCzk() : null,
                'statusUrl' => $this->statusUrlGenerator->generate($order),
            ]);

        $email->getHeaders()->addTextHeader('X-Order-Id', $order->id->toRfc4122());

        $this->mailer->send($email);
    }
}
