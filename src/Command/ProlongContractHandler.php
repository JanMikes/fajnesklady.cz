<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Contract;
use App\Enum\BillingMode;
use App\Enum\PaymentMethod;
use App\Exception\StorageHasActiveRental;
use App\Repository\ContractRepository;
use App\Repository\StorageRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Service\GoPay\GoPayClient;
use App\Service\Payment\VariableSymbolGenerator;
use App\Service\StorageAvailabilityChecker;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ProlongContractHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private StorageRepository $storageRepository,
        private StorageAvailabilityChecker $availabilityChecker,
        private GoPayClient $goPayClient,
        private VariableSymbolGenerator $variableSymbolGenerator,
        private UserRepository $userRepository,
        private AuditLogger $auditLogger,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ProlongContractCommand $command): Contract
    {
        $now = $this->clock->now();
        // Row lock serialises against the termination cron reaping this
        // contract at the endDate boundary (lock order: contract before
        // storage, matching the cron, so the two cannot deadlock).
        $contract = $this->contractRepository->getForUpdate($command->contractId);
        $previousEndDate = $contract->endDate;

        // Serialise against concurrent bookings of the same unit — the same
        // row lock OrderService::createOrder takes before persisting an order.
        $this->storageRepository->lockForBooking($contract->storage);

        // The extension window must be free of anyone ELSE's bookings. For
        // live-token card contracts this always holds (their own open-ended
        // block is excluded here) — that is the spec-076 guarantee.
        $windowIsFree = $this->availabilityChecker->isAvailable(
            $contract->storage,
            $previousEndDate,
            $command->newEndDate,
            excludeOrder: $contract->order,
            excludeContract: $contract,
        );
        if (!$windowIsFree) {
            throw StorageHasActiveRental::cannotProlong($contract->storage);
        }

        // Deactivated place = pulled from the market; existing tenants cannot
        // extend there either (defense in depth — the controller gates too).
        if (!$contract->storage->getPlace()->isActive) {
            throw new \DomainException('Pobočka již není v provozu — smlouvu nelze prodloužit.');
        }

        $actor = null !== $command->actorId ? $this->userRepository->get($command->actorId) : null;

        $contract->prolong($command->newEndDate, $actor, $now);

        if (BillingMode::ONE_TIME === $contract->billingMode) {
            // A short one-shot rental being prolonged joins the manual bank
            // track: the already-paid one-shot covered up to the previous end,
            // so the first extension cycle is due right there.
            $contract->applyBillingMode(BillingMode::MANUAL_RECURRING);
            $contract->scheduleNextBilling($previousEndDate, $previousEndDate);
        }

        if (PaymentMethod::BANK_TRANSFER === $command->switchTo && null !== $contract->goPayParentPaymentId) {
            // Card → bank: void the token now; the manual cron takes over from
            // the date the card had paid through.
            $nextBillingDate = $contract->nextBillingDate ?? $contract->paidThroughDate ?? $previousEndDate;
            $this->goPayClient->voidRecurrence($contract->goPayParentPaymentId);
            $contract->cancelRecurringPayment();
            $contract->applyBillingMode(BillingMode::MANUAL_RECURRING);
            $contract->scheduleNextBilling($nextBillingDate, null);
            $contract->order->setPaymentMethod(PaymentMethod::BANK_TRANSFER);
        }

        // Every manual-track contract needs a variable symbol for the reminder
        // e-mails / bank reconciliation.
        if (BillingMode::MANUAL_RECURRING === $contract->billingMode && null === $contract->order->variableSymbol) {
            $contract->order->assignVariableSymbol($this->variableSymbolGenerator->generate($contract->order->id));
        }

        $this->auditLogger->logContractProlonged($contract, $previousEndDate);

        return $contract;
    }
}
