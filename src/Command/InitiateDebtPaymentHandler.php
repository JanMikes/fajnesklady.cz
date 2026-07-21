<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\AllocationStepType;
use App\Repository\BankTransactionAllocationRepository;
use App\Service\GoPay\GoPayClient;
use App\Value\GoPayPayment;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class InitiateDebtPaymentHandler
{
    public function __construct(
        private GoPayClient $goPayClient,
        private BankTransactionAllocationRepository $allocationRepository,
    ) {
    }

    public function __invoke(InitiateDebtPaymentCommand $command): GoPayPayment
    {
        $order = $command->order;
        $storage = $order->storage;

        \assert(null !== $order->onboardingDebtInHaler);

        // Since spec 089 both payment methods are offered on every order, so a
        // customer can wire part of the debt and then settle the rest by card.
        // Charging the full debt here would overcharge them; charge what is
        // actually outstanding. Scoped to money allocated to the DEBT so
        // first-payment money cannot discount it (spec 091 D2).
        $partiallyPaid = $this->allocationRepository->sumForOrderByType($order, AllocationStepType::ONBOARDING_DEBT);
        $outstanding = max(0, $order->onboardingDebtInHaler - $partiallyPaid);

        $payment = $this->goPayClient->createOneTimeCharge(
            amount: $outstanding,
            orderNumber: $order->id->toRfc4122(),
            orderDescription: sprintf(
                'Dluh z předchozí smlouvy - %s (%s)',
                $storage->storageType->name,
                $storage->getPlace()->name,
            ),
            payerEmail: $order->user->email,
            returnUrl: $command->returnUrl,
            notificationUrl: $command->notificationUrl,
        );

        $order->setDebtGoPayPaymentId($payment->id);

        return $payment;
    }
}
