<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AuditLogger;
use App\Service\SignatureStorage;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SignOrderHandler
{
    public function __construct(
        private SignatureStorage $signatureStorage,
        private AuditLogger $auditLogger,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SignOrderCommand $command): void
    {
        $order = $command->order;
        $now = $this->clock->now();

        $signaturePath = $this->signatureStorage->store($order->id, $command->signatureDataUrl);

        $order->attachSignature(
            signaturePath: $signaturePath,
            signingMethod: $command->signingMethod,
            typedName: $command->typedName,
            styleId: $command->styleId,
            now: $now,
        );

        $this->auditLogger->logOrderSigned($order);
    }
}
