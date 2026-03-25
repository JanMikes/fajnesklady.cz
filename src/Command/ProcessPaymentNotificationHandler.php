<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\OrderRepository;
use App\Repository\PaymentRepository;
use App\Service\GoPay\GoPayClient;
use App\Service\OrderService;
use App\Service\PriceCalculator;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class ProcessPaymentNotificationHandler
{
    public function __construct(
        private GoPayClient $goPayClient,
        private OrderRepository $orderRepository,
        private PaymentRepository $paymentRepository,
        private OrderService $orderService,
        private PriceCalculator $priceCalculator,
        private MessageBusInterface $commandBus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessPaymentNotificationCommand $command): void
    {
        $status = $this->goPayClient->getStatus($command->goPayPaymentId);
        $now = $this->clock->now();

        // Find order by GoPay payment ID
        $order = $this->orderRepository->findByGoPayPaymentId($command->goPayPaymentId);

        if (null === $order) {
            // Could be a recurring charge notification — look up by goPayPaymentId on Payment
            $payment = $this->paymentRepository->findByGoPayPaymentId($command->goPayPaymentId);
            if (null === $payment) {
                $this->logger->info('GoPay notification for unknown payment ID', [
                    'gopay_payment_id' => $command->goPayPaymentId,
                    'state' => $status->state,
                ]);
            }

            return;
        }

        if ($status->isPaid() && $order->canBePaid()) {
            // Store parent payment ID for recurring (all orders >= 1 month)
            $needsRecurring = $this->priceCalculator->needsRecurringBilling($order->startDate, $order->endDate);
            if ($needsRecurring) {
                $order->setGoPayParentPaymentId($status->id);
            }

            $this->orderService->confirmPayment($order, $now);

            // Auto-complete the order (terms were already accepted before payment)
            if ($order->hasAcceptedTerms()) {
                $this->commandBus->dispatch(new CompleteOrderCommand($order));
            }
        } elseif ($status->isCanceled() && !$order->status->isTerminal()) {
            $order->cancel($now);
        }
    }
}
