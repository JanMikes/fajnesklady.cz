<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\AnalyticsEvent;
use App\Enum\PaymentMethod;
use App\Repository\AnalyticsEventRepository;
use App\Repository\OrderRepository;
use App\Service\Analytics\AnalyticsPayloadFactory;
use App\Service\Identity\ProvideIdentity;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Records the "first payment actually received" measurement event — the one
 * the marketing side uses as the Google Ads conversion.
 *
 * Listens to OrderPaid, recorded by Order::markPaid(). That is the single
 * point every payment path converges on: the GoPay webhook, the return page
 * (which re-queries GoPay rather than trusting the redirect), the FIO
 * bank-transfer cron, and the admin external-payment flow. A customer landing
 * back on the site cannot produce this event on its own.
 *
 * Two confirmations deliberately do NOT count as revenue, mirroring the rule
 * OrderService::confirmPayment() applies to the audit log:
 *   - PaymentMethod::EXTERNAL — money taken outside the system by the operator;
 *   - an explicit zero amount — the free/prepaid formality that auto-completes
 *     an order without any money moving.
 * Both are state-machine transitions. Reporting them would bill the campaign
 * for conversions that never carried a payment.
 */
#[AsMessageHandler]
final readonly class RecordFirstPaymentAnalyticsEventHandler
{
    public function __construct(
        private OrderRepository $orderRepository,
        private AnalyticsEventRepository $analyticsEventRepository,
        private AnalyticsPayloadFactory $payloadFactory,
        private ProvideIdentity $identityProvider,
    ) {
    }

    public function __invoke(OrderPaid $event): void
    {
        $order = $this->orderRepository->get($event->orderId);

        if (null !== $order->createdByAdmin) {
            return;
        }

        if (PaymentMethod::EXTERNAL === $order->paymentMethod || 0 === $event->amountOverride) {
            return;
        }

        // Same figure RecordPaymentOnOrderPaidHandler writes to the Payment row,
        // so the conversion value and the books never disagree.
        $amountInHaler = $event->amountOverride ?? $order->firstPaymentPrice;

        // Guard against a second OrderPaid for one order ever producing a
        // duplicate conversion. The paid-state transition is already guarded by
        // canBePaid(), so this is belt-and-braces on the money-reporting side.
        if ($this->analyticsEventRepository->existsForOrderAndName($order, AnalyticsEvent::FIRST_PAYMENT_SUCCESS)) {
            return;
        }

        $this->analyticsEventRepository->save(new AnalyticsEvent(
            id: $this->identityProvider->next(),
            order: $order,
            name: AnalyticsEvent::FIRST_PAYMENT_SUCCESS,
            payload: $this->payloadFactory->firstPaymentSuccess($order, $amountInHaler),
            occurredAt: $event->occurredOn,
        ));
    }
}
