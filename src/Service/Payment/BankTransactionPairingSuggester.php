<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\BankTransaction;
use App\Repository\BankTransactionRepository;
use App\Repository\OrderRepository;
use App\Repository\UserRepository;
use App\Value\PairingSuggestion;

/**
 * Guesses where an unmatched bank transfer belongs so the admin list can show
 * "Návrh: <customer / order>" next to it. Suggestions are DISPLAY-ONLY — when
 * the VS didn't resolve, the money must never move without a human walking
 * through the pairing confirmation, so nothing here pairs anything.
 *
 * Heuristics, strongest first, and only unambiguous hits suggest:
 *  1. The sender account paid before and every previous pairing points at one
 *     customer — suggest their most recently paired order.
 *  2. The sender name equals exactly one customer's name — suggest that
 *     customer's newest order.
 */
final readonly class BankTransactionPairingSuggester
{
    public function __construct(
        private BankTransactionRepository $bankTransactionRepository,
        private UserRepository $userRepository,
        private OrderRepository $orderRepository,
    ) {
    }

    /**
     * Suggestions for every unmatched transaction in the list, keyed by the
     * transaction id (RFC 4122).
     *
     * @param BankTransaction[] $transactions
     *
     * @return array<string, PairingSuggestion>
     */
    public function suggestForUnmatched(array $transactions): array
    {
        $suggestions = [];

        foreach ($transactions as $transaction) {
            if (!$transaction->isUnmatched()) {
                continue;
            }

            $suggestion = $this->suggestFor($transaction);
            if (null !== $suggestion) {
                $suggestions[$transaction->id->toRfc4122()] = $suggestion;
            }
        }

        return $suggestions;
    }

    public function suggestFor(BankTransaction $transaction): ?PairingSuggestion
    {
        return $this->suggestBySenderAccount($transaction)
            ?? $this->suggestBySenderName($transaction);
    }

    private function suggestBySenderAccount(BankTransaction $transaction): ?PairingSuggestion
    {
        if (null === $transaction->senderAccountNumber || '' === $transaction->senderAccountNumber) {
            return null;
        }

        $previouslyPaired = $this->bankTransactionRepository->findPairedBySenderAccount($transaction->senderAccountNumber);
        if ([] === $previouslyPaired) {
            return null;
        }

        // One account funding several customers' orders (a parent paying for two
        // tenants, a company account) is ambiguous — suggest nothing.
        $userIds = [];
        foreach ($previouslyPaired as $paired) {
            $pairedOrder = $paired->pairedOrder;
            if (null !== $pairedOrder) {
                $userIds[$pairedOrder->user->id->toRfc4122()] = true;
            }
        }

        if (1 !== count($userIds)) {
            return null;
        }

        $latestPairedOrder = $previouslyPaired[0]->pairedOrder;
        if (null === $latestPairedOrder) {
            return null;
        }

        return new PairingSuggestion(
            order: $latestPairedOrder,
            reason: 'Dřívější platba ze stejného účtu',
        );
    }

    private function suggestBySenderName(BankTransaction $transaction): ?PairingSuggestion
    {
        if (null === $transaction->senderName || '' === trim($transaction->senderName)) {
            return null;
        }

        $users = $this->userRepository->findByFullName($transaction->senderName);
        if (1 !== count($users)) {
            return null;
        }

        $order = $this->orderRepository->findLatestByUser($users[0]);
        if (null === $order) {
            return null;
        }

        return new PairingSuggestion(
            order: $order,
            reason: 'Shoda jména odesílatele',
        );
    }
}
