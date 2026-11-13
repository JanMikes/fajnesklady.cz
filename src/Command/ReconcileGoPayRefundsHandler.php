<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\PaymentRepository;
use App\Service\Refund\PaymentRefundService;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ReconcileGoPayRefundsHandler
{
    public function __construct(
        private PaymentRepository $paymentRepository,
        private PaymentRefundService $refundService,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ReconcileGoPayRefundsCommand $command): void
    {
        $payment = $this->paymentRepository->findByGoPayPaymentId($command->goPayPaymentId);
        if (null === $payment) {
            return;
        }

        $this->refundService->reconcile($payment, $this->clock->now());
    }
}
