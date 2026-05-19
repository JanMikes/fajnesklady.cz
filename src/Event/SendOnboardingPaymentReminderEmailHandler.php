<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\OrderRepository;
use App\Service\Onboarding\OnboardingReminderSchedule;
use App\Service\OrderStatusUrlGenerator;
use App\Service\Place\PlaceAddressFormatter;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

/**
 * Sends the D+2 / D+5 nudge for admin-onboarded customers who signed but
 * never completed payment. The status URL exposes the "Zaplatit nyní" CTA
 * (per spec 020) so the customer can pick up where they left off.
 */
#[AsMessageHandler]
final readonly class SendOnboardingPaymentReminderEmailHandler
{
    public function __construct(
        private OrderRepository $orderRepository,
        private OrderStatusUrlGenerator $statusUrlGenerator,
        private PlaceAddressFormatter $addressFormatter,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(OnboardingPaymentReminderRequested $event): void
    {
        $order = $this->orderRepository->get($event->orderId);
        $user = $order->user;
        $storage = $order->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        $subject = match ($event->stage) {
            OnboardingReminderSchedule::STAGE_D_PLUS_2 => 'Připomínáme: dokončete platbu objednávky — Fajnesklady.cz',
            OnboardingReminderSchedule::STAGE_D_PLUS_5 => 'Druhá připomínka: vaše objednávka stále čeká na platbu',
            default => null,
        };

        if (null === $subject) {
            $this->logger->warning('Unknown onboarding reminder stage', [
                'stage' => $event->stage,
                'order_id' => $order->id->toRfc4122(),
            ]);

            return;
        }

        $statusUrl = $this->statusUrlGenerator->generate($order);

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to(new Address($user->email, $user->fullName))
            ->subject($subject)
            ->htmlTemplate('email/onboarding_payment_reminder.html.twig')
            ->context([
                'name' => $user->fullName,
                'stage' => $event->stage,
                'placeName' => $place->name,
                'placeAddress' => $this->addressFormatter->format($place),
                'placeNavigationUrl' => $this->addressFormatter->navigationUrl($place),
                'storageType' => $storageType->name,
                'storageNumber' => $storage->number,
                'startDate' => $order->startDate->format('d.m.Y'),
                'endDate' => $order->endDate?->format('d.m.Y') ?? 'Na dobu neurčitou',
                'amountInCzk' => number_format($order->getFirstPaymentPriceInCzk(), 0, ',', ' '),
                'isRecurring' => $order->isRecurring(),
                'statusUrl' => $statusUrl,
            ]);

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send onboarding payment reminder email', [
                'order_id' => $order->id->toRfc4122(),
                'stage' => $event->stage,
                'exception' => $e,
            ]);
        }
    }
}
