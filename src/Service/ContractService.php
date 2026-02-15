<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Contract;
use App\Entity\Order;
use App\Repository\ContractRepository;
use App\Service\GoPay\GoPayClient;

/**
 * Service for managing contract lifecycle.
 *
 * Handles: creation from order, termination, expiration tracking.
 */
final readonly class ContractService
{
    public function __construct(
        private ContractRepository $contractRepository,
        private ContractDocumentGenerator $documentGenerator,
        private AuditLogger $auditLogger,
        private GoPayClient $goPayClient,
        private string $contractTemplatePath,
    ) {
    }

    /**
     * Generate and attach document to a contract.
     *
     * @return string Path to the generated document
     */
    public function generateDocument(Contract $contract, \DateTimeImmutable $now): string
    {
        $documentPath = $this->documentGenerator->generate($contract, $this->contractTemplatePath);
        $contract->attachDocument($documentPath, $now);

        return $documentPath;
    }

    /**
     * Sign a contract.
     */
    public function signContract(Contract $contract, \DateTimeImmutable $now): void
    {
        if ($contract->isSigned()) {
            throw new \DomainException('Contract is already signed.');
        }

        $contract->sign($now);
        $this->auditLogger->logContractSigned($contract);
    }

    /**
     * Terminate a contract (for unlimited rentals or early termination).
     */
    public function terminateContract(Contract $contract, \DateTimeImmutable $now): void
    {
        if ($contract->isTerminated()) {
            throw new \DomainException('Contract is already terminated.');
        }

        // Cancel recurring payment in GoPay if active
        if ($contract->hasActiveRecurringPayment()) {
            /** @var string $parentPaymentId */
            $parentPaymentId = $contract->goPayParentPaymentId;
            $this->goPayClient->voidRecurrence($parentPaymentId);
            $contract->cancelRecurringPayment();
        }

        $contract->terminate($now);
        $this->auditLogger->logContractTerminated($contract);
        $this->auditLogger->logStorageReleased($contract->storage, 'Contract terminated');
    }

    /**
     * Get contract for an order.
     */
    public function getContractForOrder(Order $order): ?Contract
    {
        return $this->contractRepository->findByOrder($order);
    }

    /**
     * Find contracts expiring within specified days.
     *
     * @return Contract[]
     */
    public function findExpiringContracts(int $days, \DateTimeImmutable $now): array
    {
        return $this->contractRepository->findExpiringWithinDays($days, $now);
    }

    /**
     * Find contracts expiring in exactly N days (for reminder emails).
     *
     * @return Contract[]
     */
    public function findContractsExpiringOnDay(int $daysFromNow, \DateTimeImmutable $now): array
    {
        $targetDate = $now->modify("+{$daysFromNow} days")->setTime(0, 0, 0);
        $nextDay = $targetDate->modify('+1 day');

        $contracts = $this->contractRepository->findExpiringWithinDays($daysFromNow, $now);

        // Filter to only contracts expiring on the exact target date
        return array_filter($contracts, function (Contract $contract) use ($targetDate, $nextDay) {
            if (null === $contract->endDate) {
                return false;
            }
            $endDate = $contract->endDate->setTime(0, 0, 0);

            return $endDate >= $targetDate && $endDate < $nextDay;
        });
    }

    /**
     * Check if contract can be terminated.
     */
    public function canTerminate(Contract $contract): bool
    {
        return !$contract->isTerminated();
    }

    /**
     * Get days remaining until contract expires.
     */
    public function getDaysRemaining(Contract $contract, \DateTimeImmutable $now): ?int
    {
        if (null === $contract->endDate) {
            return null; // Unlimited contract
        }

        if ($contract->isTerminated()) {
            return null;
        }

        $diff = $now->diff($contract->endDate);

        if ($diff->invert) {
            return 0; // Already expired
        }

        // Days is false only when using DateTimeImmutable::diff with relative DateInterval
        // For absolute date diffs, it's always an int
        return (int) $diff->days;
    }
}
