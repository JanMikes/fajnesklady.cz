# 065 — User detail: orders list + financial overview

**Status:** done
**Type:** feature (admin UX)
**Scope:** medium (1 controller, 1 template, 2–3 repo methods, 1 OverdueChecker method)
**Depends on:** spec 067 (uses `OrderReferenceFormatter` to show each order's number) — soft; can stub the reference inline if 067 not yet done

## Problem

The admin user-detail page (`/portal/users/{id}` → `UserViewController`) is **identity-only**: name, email, verification/activation badges, roles, admin actions. It shows **nothing about the customer's actual business** — no orders, no contracts, no debt, no past-due, no dates, no "what and where." To understand a customer (e.g. when they call), an admin has to cross-reference the orders list manually.

## Goal

The user-detail page gains (a) a compact **financial overview** (active contracts, MRR/YRR, total outstanding debt, overdue flag) and (b) a **table of all the user's orders** with the brief info that matters: order reference, status, what (storage type) & where (place + storage number), period (start–end), price, and per-order debt / past-due indicators. An admin sees everything related to the user on one screen.

## Context (current state)

- `src/Controller/Portal/UserViewController.php` — `#[IsGranted('ROLE_ADMIN')]`, loads `$userRepository->get($id)` and renders `templates/portal/user/view.html.twig` with just `{user}`. (This area uses repositories directly, **not** the query bus — follow that local pattern.)
- `templates/portal/user/view.html.twig` — identity grid + action buttons + deactivation modal. Add new sections below the identity grid.
- Reusable enrichment (from the user list, `UserListController`):
  - `ContractRepository::loadCustomerStatsByUserIds([$userId], $now)` → `{activeCount, totalCount, mrrInHaler, yrrInHaler}` keyed by user-id. Call with a single-element array.
  - `OverdueChecker` (`src/Service/Overdue/OverdueChecker.php`) — classifies overdue contracts. Add a per-user method (Req 3).
- Per-order / per-contract data:
  - `Order` (`src/Entity/Order.php`): `status` (OrderStatus enum), `storage` (→ `storageType`, `place`), `startDate`, `endDate`, `firstPaymentPrice`, `individualMonthlyAmount`, `paidThroughDate`, `paymentFrequency`, `billingMode`, `createdAt`, `onboardingDebtInHaler` / `debtPaidAt` (order-level onboarding debt, spec 051), `rentalType`, `isRecurring()`.
  - `Contract` is `OneToOne` → `Order` (`Contract.php:121`); fields `outstandingDebtAmount`, `failedBillingAttempts`, `nextBillingDate`, `terminatedAt`, `hasOutstandingDebt()`. No inverse `Order::$contract`, so map order→contract via a repo lookup (Req 2).
- `customer_billing_status.html.twig` exists (customer-facing billing badge) — reuse if helpful, otherwise plain badges.

## Requirements

### 1. Fetch the user's orders (with details), newest first

`OrderRepository::findByUserWithDetails(Uuid $userId): list<Order>` — QueryBuilder selecting the user's orders, `LEFT JOIN`-fetching `storage`, `storage.storageType`, `storage.place` to avoid N+1, ordered `createdAt DESC`. (Follow the repo style: `createQueryBuilder()`, no `getRepository`/`findBy`.)

### 2. Map each order to its contract

`ContractRepository::findByOrderIds(array $orderIds): array<string, Contract>` — keyed by `order.id->toRfc4122()`; QueryBuilder `WHERE c.order IN (:ids)`. Empty input → `[]`. Used to attach contract financial state per order.

### 3. Per-user overdue views

`OverdueChecker::findOverdueViewsForUser(\DateTimeImmutable $now, Uuid $userId): list<OverdueContractView>` — sibling to the existing `summariseForPlace`; same classification, restricted to the user's contracts. Controller turns it into a `array<string, OverdueContractView>` keyed by `contract.id->toRfc4122()` so the template can badge an order's contract with severity / daysOverdue / overdueAmount / reasonLabel.

### 4. Controller assembly

`UserViewController` additionally:
```php
$orders = $this->orderRepository->findByUserWithDetails($user->id);
$contractsByOrderId = $this->contractRepository->findByOrderIds(array_map(fn (Order $o) => $o->id, $orders));
$stats = $this->contractRepository->loadCustomerStatsByUserIds([$user->id], $now)[$user->id->toRfc4122()] ?? null;
$overdueViewsByContractId = /* from findOverdueViewsForUser keyed by contract id */;
$totalDebtInHaler = /* sum of outstandingDebtAmount across the user's contracts + unpaid order onboardingDebt */;
```
Pass `orders`, `contractsByOrderId`, `stats`, `overdueViewsByContractId`, `totalDebtInHaler`, `now` to the template. Inject `OrderRepository`, `ContractRepository`, `OverdueChecker`, a clock.

### 5. Template — financial overview card

Above the orders table: small KPI row — "Aktivní smlouvy" (`stats.activeCount` / `totalCount`), "MRR" (`stats.mrrInHaler`), "YRR" (`stats.yrrInHaler` if >0), "Celkový dluh" (`totalDebtInHaler`, red when >0), and an overall "Po splatnosti" badge if any overdue view exists. Reuse the haléř→Kč formatting (`(x / 100)|number_format(0, ',', ' ')`).

### 6. Template — orders table

Columns:
- **Číslo** — `order_reference(order)` (spec 067 Twig function) or the inline `Y-md-UUID8`; link to `admin_order_detail`.
- **Stav** — `order.status` badge (mirror the mapping in `templates/admin/order/list.html.twig`).
- **Co / kde** — `order.storage.storageType.name` + storage number `order.storage.number`, place `order.storage.place.name`.
- **Období** — `startDate – endDate` (or "automat. prodlužování" when `endDate` null).
- **Cena** — `firstPaymentPrice` (+ "/ měsíc" when `isRecurring()`); show "Indiv." / "Zdarma" chips when `individualMonthlyAmount` is set / 0 (mirror order list).
- **Dluh / splatnost** — using `contractsByOrderId[order.id]` + `overdueViewsByContractId[contract.id]`: red "Dluh {amount} Kč" when `outstandingDebtAmount > 0`; severity badge + "{daysOverdue} dní po splatnosti" when an overdue view exists; plus an amber "Onboarding dluh {amount} Kč" when `order.onboardingDebtInHaler > 0` and `debtPaidAt` null. "—" when clean.

Empty state: "Zákazník nemá žádné objednávky." when `orders` is empty.

## Acceptance

- [ ] Visiting `/portal/users/{id}` for a customer with orders shows the financial overview card (active/total contracts, MRR, YRR when >0, total debt, overall po-splatnosti badge) and a table of all their orders.
- [ ] Each order row shows reference (linking to the admin order detail), status, storage type + number + place, period, price, and any debt / past-due indicator.
- [ ] A user with no orders shows the empty state and a zeroed/′—′ overview, no errors.
- [ ] No N+1: storage/type/place are join-fetched; contracts and overdue views are batch-loaded once.
- [ ] The reference shown equals the canonical order reference (spec 067).
- [ ] `composer quality` green; `composer test` green (controller renders for a fixture user with orders).

## Out of scope

- **Editing orders / contracts from this page.** Read-only overview; existing action buttons unchanged. Row links go to the existing admin order detail.
- **Invoices / payments / handover history timeline.** Could be a later addition; this spec covers orders + financial summary.
- **Pagination of the orders table.** A single customer's order count is small; render all.

## Open questions

None — proceed.
