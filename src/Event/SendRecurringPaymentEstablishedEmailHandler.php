<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\OrderRepository;
use App\Service\PriceCalculator;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Sends the "your recurring payment was established" confirmation e-mail
 * required by Podmínky opakovaných plateb čl. IV (within 2 working days of
 * customer consent / first successful charge).
 *
 * The e-mail enumerates the recurring parameters that the customer agreed to
 * (purpose, fixed amount, legal max, frequency, debit day, duration,
 * cancellation contact) so they have a written record outside the order flow.
 */
#[AsMessageHandler]
final readonly class SendRecurringPaymentEstablishedEmailHandler
{
    public function __construct(
        private OrderRepository $orderRepository,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RecurringPaymentEstablished $event): void
    {
        $order = $this->orderRepository->get($event->orderId);
        $user = $order->user;
        $storage = $order->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        $manageUrl = $this->urlGenerator->generate(
            'portal_user_order_detail',
            ['id' => $order->id->toRfc4122()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to(new Address($user->email, $user->fullName))
            ->subject('Opakovaná platba byla úspěšně nastavena - Fajnesklady.cz')
            ->htmlTemplate('email/recurring_payment_established.html.twig')
            ->context([
                'name' => $user->fullName,
                'placeName' => $place->name,
                'storageType' => $storageType->name,
                'storageNumber' => $storage->number,
                'amountInCzk' => number_format($event->amount / 100, 2, ',', ' '),
                'recurringPaymentLegalMaxInCzk' => intdiv(PriceCalculator::MAX_RECURRING_PAYMENT_AMOUNT_IN_HALER, 100),
                'debitDay' => $event->occurredOn->format('j.'),
                'establishedOn' => $event->occurredOn->format('d.m.Y'),
                'manageUrl' => $manageUrl,
            ]);

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send recurring-payment-established e-mail', [
                'order_id' => $event->orderId->toRfc4122(),
                'exception' => $e,
            ]);
        }
    }
}
