<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\GoPay\GoPayClient;
use App\Value\GoPayPayment;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class InitiateDebtPaymentHandler
{
    public function __construct(
        private GoPayClient $goPayClient,
    ) {
    }

    public function __invoke(InitiateDebtPaymentCommand $command): GoPayPayment
    {
        $order = $command->order;
        $storage = $order->storage;

        \assert(null !== $order->onboardingDebtInHaler);

        $payment = $this->goPayClient->createOneTimeCharge(
            amount: $order->onboardingDebtInHaler,
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
