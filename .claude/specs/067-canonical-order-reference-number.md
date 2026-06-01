# 067 — Canonical order reference number (one number across email, contract, status, admin)

**Status:** done
**Type:** refactor (consistency) + UX
**Scope:** small–medium (1 new formatter service + ~5 call-site swaps; no DB migration)
**Depends on:** none

## Problem

The "number" a customer can quote back to support exists today in **three inconsistent forms**, derived from two different entities:

| Touchpoint | Source | Format | Example |
|---|---|---|---|
| `order_placed` email "Číslo objednávky" (`SendOrderPlacedEmailHandler.php:63`) | `order.id` | bare uuid8, lowercase | `019e4643` |
| Contract DOCX `${CONTRACT_NUMBER}` (`ContractDocumentGenerator::formatDocumentNumberForOrder`) | **order**.id + order.createdAt | `Y-md-UUID8` upper | `2026-0601-019E4643` |
| `rental_activated` / `contract_expiring` emails + `order_status` "Číslo smlouvy" (`SendRentalActivatedEmailHandler.php:200`, `SendContractExpiringReminderHandler.php:83`) | **contract**.id + contract.createdAt | `Y-md-UUID8` upper | `2026-0610-01A2B3C4` |

A customer who signed a contract sees one number on the PDF and a *different* number in the activation email. Support can't reliably look an order up by "their number," and the admin grids (specs 065/068) have no single number to display. The user's requirement: **one number, the same everywhere, that admin can search by.**

## Goal

A single canonical **order reference**, formatted `Y-md-UUID8` (upper-case), **derived from the order** (so it equals the number already printed on the signed contract DOCX — the legally meaningful artefact). It is produced by one shared formatter, reused by every customer touchpoint and by the admin grids, so the customer, the contract, the emails, the public status page, and the admin UI all show the identical string.

## Context (current state)

- `src/Service/ContractDocumentGenerator.php:250` `formatDocumentNumber(Uuid $id, \DateTimeImmutable $date)` and `:80` `formatDocumentNumberForOrder(Order $order)` — already order-derived `Y-md-UUID8`. **This is the canonical format; the contract PDF stays byte-identical.**
- `src/Event/SendRentalActivatedEmailHandler.php:200` and `src/Event/SendContractExpiringReminderHandler.php:83` — private `formatContractNumber(Contract)` using **contract** id/date. These two are the offenders to realign.
- `src/Event/SendOrderPlacedEmailHandler.php:63` — `substr($order->id->toRfc4122(), 0, 8)` (bare uuid8). Realign to the full canonical format.
- `templates/email/order_placed.html.twig:94` (`{{ orderNumber }}`), `templates/email/rental_activated.html.twig:98` (`{{ contractNumber }}`), `templates/public/order_status.html.twig:242` ("Číslo smlouvy") — display sites.
- `.claude/COMPLIANCE.md` governs the **contract PDF / legal text**. The contract DOCX number is unchanged here (already order-derived). Email + status-page wording is not in the locked legal-PDF set, but the label change is customer-facing — keep the existing Czech labels ("Číslo objednávky" / "Číslo smlouvy") and only change the *value* they render, so no legal wording shifts.

## Requirements

### 1. Shared formatter

New `src/Service/Order/OrderReferenceFormatter.php`:
```php
final readonly class OrderReferenceFormatter
{
    public function format(Order $order): string
    {
        return sprintf(
            '%s-%s-%s',
            $order->createdAt->format('Y'),
            $order->createdAt->format('md'),
            strtoupper(substr($order->id->toRfc4122(), 0, 8)),
        );
    }
}
```
Optionally expose a Twig function `order_reference(order)` via a small Twig extension so templates/grids render it without controller plumbing (preferred for the admin grids in 065/068).

### 2. Realign `ContractDocumentGenerator` to delegate

Have `formatDocumentNumberForOrder()` call the new formatter (single source of truth); keep its public signature so existing callers (`OrderEmailAttachmentsService.php:88,136`) are unaffected and the DOCX output is identical.

### 3. Realign the contract-derived email handlers

In `SendRentalActivatedEmailHandler` and `SendContractExpiringReminderHandler`, replace `formatContractNumber(Contract)` with `OrderReferenceFormatter::format($contract->order)` (Contract is `OneToOne` → Order; access `$contract->order`). Delete the now-dead private methods. The "Číslo smlouvy" label stays; only the value aligns to the order-derived reference.

### 4. Realign `order_placed`

`SendOrderPlacedEmailHandler` passes `orderNumber => $this->orderReferenceFormatter->format($order)` instead of the bare substr.

### 5. Public status page

`order_status.html.twig:242` renders the canonical reference (via the Twig function or a controller-passed value).

## Acceptance

- [ ] A single order's reference string is identical across: the signed contract PDF, the order-placement email, the rental-activated email, the contract-expiring email, and the public status page.
- [ ] The contract DOCX output is byte-identical to before (format unchanged; only the producing code is centralized).
- [ ] `OrderReferenceFormatter::format()` returns `Y-md-UUID8` upper-case derived from `order.createdAt` + `order.id`.
- [ ] No DB migration; no change to Czech labels, only the rendered values.
- [ ] `composer quality` is green; `composer test` green for affected email handlers.

## Out of scope

- **Sequential human numbers** (e.g. `2026-0001`). Rejected for now — needs a counter + migration + concurrency handling; the deterministic order-derived reference meets the "one number for communication" need without schema change.
- **Back-filling historical emails.** Customers who already received a contract-derived "Číslo smlouvy" keep it; admin order search (spec 068) compensates by matching both order-id and contract-id prefixes.
- **Invoice numbers / variable symbols** — separate identifiers, untouched.

## Open questions

None — proceed.
