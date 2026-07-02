<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\BillingMode;
use App\Enum\PaymentMethod;
use App\Service\Payment\VariableSymbolGenerator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SetOrderPaymentPreferencesHandler
{
    public function __construct(
        private VariableSymbolGenerator $variableSymbolGenerator,
    ) {
    }

    public function __invoke(SetOrderPaymentPreferencesCommand $command): void
    {
        $order = $command->order;

        // AUTO is the entity default; only a non-default choice needs a set.
        if (BillingMode::AUTO_RECURRING !== $command->billingMode) {
            $order->setBillingMode($command->billingMode);
        }

        if (null !== $command->paymentMethod) {
            $order->setPaymentMethod($command->paymentMethod);
        }

        if (PaymentMethod::BANK_TRANSFER === $command->paymentMethod) {
            $order->assignVariableSymbol(
                $this->variableSymbolGenerator->generate($order->id),
            );
        }
    }
}
