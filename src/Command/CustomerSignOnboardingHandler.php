<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\PaymentMethod;
use App\Service\OrderService;
use App\Service\SignatureStorage;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class CustomerSignOnboardingHandler
{
    public function __construct(
        private SignatureStorage $signatureStorage,
        private OrderService $orderService,
        private ClockInterface $clock,
        private MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(CustomerSignOnboardingCommand $command): void
    {
        $now = $this->clock->now();
        $order = $command->order;

        // 1. Validate order state
        if (null === $order->signingToken) {
            throw new \DomainException('Order has no signing token.');
        }

        if (true !== $order->isAdminCreated) {
            throw new \DomainException('Order is not an admin-created onboarding order.');
        }

        if (!$order->canBePaid()) {
            throw new \DomainException('Order cannot be signed in its current state.');
        }

        // 2. Store signature
        $signaturePath = $this->signatureStorage->store($order->id, $command->signatureDataUrl);

        // 3. Attach signature to order
        $order->attachSignature(
            signaturePath: $signaturePath,
            signingMethod: $command->signingMethod,
            typedName: $command->typedName,
            styleId: $command->styleId,
            signingPlace: $command->signingPlace,
            now: $now,
        );

        // 4. Accept terms + reserve storage
        $order->acceptTerms($now);
        $order->reserve($now);

        // 5. Clear signing token (prevents reuse)
        $order->clearSigningToken();

        // 6. Handle based on payment method
        if (PaymentMethod::EXTERNAL === $order->paymentMethod) {
            // External payment: auto-complete the order
            $this->orderService->confirmPayment($order, $now);
            $this->commandBus->dispatch(new CompleteOrderCommand(order: $order));
        }
        // For GOPAY: order stays in RESERVED state, customer proceeds to GoPay payment page
    }
}
