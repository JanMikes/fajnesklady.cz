<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Fine;
use App\Service\GoPay\GoPayClient;
use App\Value\GoPayPayment;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class InitiateFinePaymentHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private GoPayClient $goPayClient,
    ) {
    }

    public function __invoke(InitiateFinePaymentCommand $command): GoPayPayment
    {
        $fine = $this->entityManager->find(Fine::class, $command->fineId);
        if (null === $fine) {
            throw new \DomainException('Fine not found.');
        }

        if (!$fine->isPayable()) {
            throw new \DomainException('Fine is not payable.');
        }

        $payment = $this->goPayClient->createOneTimeCharge(
            amount: $fine->amountInHaler,
            orderNumber: sprintf('FINE-%s', $fine->id->toRfc4122()),
            orderDescription: sprintf('Smluvní pokuta - %s', $fine->type->label()),
            payerEmail: $fine->user->email,
            returnUrl: $command->returnUrl,
            notificationUrl: $command->notificationUrl,
        );

        $fine->setGoPayPayment($payment->id, $payment->gwUrl);

        return $payment;
    }
}
