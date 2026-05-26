<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\OnboardingReminderSent;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Event\OnboardingPaymentReminderRequested;
use App\Repository\OnboardingReminderSentRepository;
use App\Repository\OrderRepository;
use App\Service\Identity\ProvideIdentity;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Per-stage idempotent dispatcher for onboarding-payment reminders. Mirrors
 * {@see DispatchManualBillingNotificationHandler} (spec 036) at three layers:
 *
 *   - schema unique constraint on (order_id, stage)
 *   - record-FIRST-then-dispatch so any crash leaves the row behind
 *   - SELECT ... FOR UPDATE serialises parallel cron processes
 *
 * Email side-effects fan out via the event bus AFTER this transaction commits
 * (DomainEventsMiddleware semantics), so an SMTP failure does not roll back
 * the OnboardingReminderSent row — we prefer "miss one reminder" over
 * "double-send".
 */
#[AsMessageHandler]
final readonly class DispatchOnboardingReminderHandler
{
    public function __construct(
        private OrderRepository $orderRepository,
        private OnboardingReminderSentRepository $reminderRepository,
        #[Autowire(service: 'event.bus')]
        private MessageBusInterface $eventBus,
        private ClockInterface $clock,
        private ProvideIdentity $identityProvider,
    ) {
    }

    public function __invoke(DispatchOnboardingReminderCommand $command): void
    {
        $now = $this->clock->now();
        $order = $this->orderRepository->get($command->orderId);

        // Pessimistic lock — parallel cron processes serialise here.
        $existing = $this->reminderRepository->findByOrderAndStageWithLock($order, $command->stage);
        if (null !== $existing) {
            return;
        }

        // Re-verify state hasn't drifted since the cron query.
        if (true !== $order->isAdminCreated
            || !in_array($order->paymentMethod, [PaymentMethod::GOPAY, PaymentMethod::BANK_TRANSFER], true)
            || !in_array($order->status, [OrderStatus::RESERVED, OrderStatus::AWAITING_PAYMENT], true)
            || null === $order->signedAt
        ) {
            return;
        }

        // Record FIRST so any later crash leaves the row in place — the
        // unique constraint then blocks duplicates on the next cron run.
        $this->reminderRepository->save(new OnboardingReminderSent(
            id: $this->identityProvider->next(),
            order: $order,
            stage: $command->stage,
            sentAt: $now,
        ));

        $this->eventBus->dispatch(new OnboardingPaymentReminderRequested(
            orderId: $order->id,
            stage: $command->stage,
            occurredOn: $now,
        ));
    }
}
