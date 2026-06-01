# 068 — Orders list: number column, per-order debt / past-due badges, search

**Status:** done
**Type:** feature (admin UX)
**Scope:** medium (controller, template, 1–2 repo query methods)
**Depends on:** spec 067 (canonical `OrderReferenceFormatter` for the number column + search normalization). Reuses `ContractRepository::findByOrderIds` from spec 065 (Req 2).

## Problem

`/portal/admin/orders` shows the order as a truncated UUID (`id|slice(0,8)`), a per-**user** "Dlužník" badge (not per-order), and has **no search**. Admins can't (a) see at a glance that *this order* has debt or is past due, nor (b) look an order up by the number a customer quotes or by customer name.

## Goal

The orders grid (1) shows the **canonical order reference** (spec 067) as the "Číslo" column — the same number on the contract/emails, (2) shows **per-order debt and past-due indicators** (contract outstanding debt, overdue status, plus order-level onboarding debt), and (3) gains a **search** box matching the order reference **or** customer name/email.

## Context (current state)

- `src/Controller/Admin/AdminOrderListController.php` — `page` + `filter ∈ {individual, external, ending, free}`; `OrderRepository::findAllPaginated` / `findAdminFiltered` + counts; pre-computes per-user `debtorIdSet` via `OverdueChecker::filterOverdueUserIds`. Renders `templates/admin/order/list.html.twig`.
- `src/Repository/OrderRepository.php` `buildAdminFilteredQueryBuilder(now, filter)` (lines ~492-524) — the QB to extend with an optional search join/predicate.
- `templates/admin/order/list.html.twig` — columns: ID objednávky (`id|slice(0,8)`), Zákazník (+per-user Dlužník badge), Sklad, Pobočka, Období, Cena, Stav, Vytvořeno, Akce. Status-badge map already present.
- Per-order finance: `Contract` (`OneToOne`→Order) `outstandingDebtAmount`, overdue classification in `OverdueChecker`; order-level onboarding debt `order.onboardingDebtInHaler` / `order.debtPaidAt`. Map order→contract via `ContractRepository::findByOrderIds(orderIds)` (spec 065 Req 2).
- The customer's quotable number is order-derived `Y-md-UUID8` (spec 067); historical "Číslo smlouvy" was contract-derived — so **search must match both** order-id and contract-id prefixes for back-compat.

## Requirements

### 1. Order reference column

Replace the "ID objednávky" cell with `order_reference(order)` (spec 067 Twig function), monospace, linking to `admin_order_detail`. Header label "Číslo".

### 2. Per-order debt / past-due indicators

Controller: for the page's orders, `contractsByOrderId = ContractRepository::findByOrderIds(pageOrderIds)` and a page-scoped overdue set. Add `ContractRepository::findOverdueContractIds(\DateTimeImmutable $now, array $orderIds): list<string>` (contract-id strings) mirroring the existing `findOverdueUserIds` WHERE logic but selecting `IDENTITY(c)` restricted to `c.order IN (:orderIds)`. Pass `contractsByOrderId`, `overdueContractIdSet` (flipped), `now`.

Template (in/near the Stav column) per order, using its contract:
- `outstandingDebtAmount > 0` → red badge "Dluh {amount} Kč".
- contract id ∈ `overdueContractIdSet` → red badge "Po splatnosti".
- `order.onboardingDebtInHaler > 0 and debtPaidAt is null` → amber badge "Onboarding dluh {amount} Kč".
- none → nothing extra.
Keep the customer name + email; the old per-user "Dlužník" badge under the customer may be removed in favour of these per-order badges (they're more precise).

### 3. Search (order reference or customer)

Extend `buildAdminFilteredQueryBuilder` to accept an optional `?string $search` and, when set, `LEFT JOIN` `User u` (and `Contract c ON c.order = o`) and add:
```
(CAST(o.id AS string) LIKE :ref OR CAST(c.id AS string) LIKE :ref
 OR LOWER(u.fullName) LIKE :nameq OR LOWER(u.email) LIKE :nameq)
```
Controller normalization (handles `2026-0601-019E4643`, `019E4643`, or a name):
```php
$raw = trim((string) $request->query->get('q', ''));
$search = '' === $raw ? null : $raw;
// id token = last '-' segment, lowercased → matches o.id / c.id prefix
$refToken = strtolower(substr($raw, strrpos($raw, '-') !== false ? strrpos($raw, '-') + 1 : 0));
// bind :ref = $refToken.'%' ; :nameq = '%'.mb_strtolower($raw).'%'
```
(If `c.id::text`/`CAST` differs by DBAL platform, use a native query like the existing DBAL methods; Postgres `id::text ILIKE :ref` is fine.) Wire `findAdminFiltered` + `countByAdminFilter` (+ the `ending`/`individual`/etc. filters) to pass search through; search combines with the active filter (AND). Use the same `buildAdminFilteredQueryBuilder` for both list + count so they stay consistent.

### 4. Template — search form

Add a `GET` search form above the chips: input `q` (placeholder "Hledat číslo objednávky nebo zákazníka…"), submit + clear, with `filter` carried as hidden. Chips preserve `q`; `pagination.html.twig` `routeParams` include `{filter, q}`. Empty state: "Žádné objednávky neodpovídají hledání."

### 5. Export parity (small)

`AdminOrderExportController` / `streamAdminFiltered` accept the same `search` so an exported view matches the screen; swap the export's "Číslo objednávky" value to the canonical reference (spec 067) too.

## Acceptance

- [ ] The orders grid "Číslo" column shows the canonical order reference (matching the contract/emails) and links to the order detail.
- [ ] An order whose contract has outstanding debt shows a red "Dluh … Kč"; an overdue order shows "Po splatnosti"; an unpaid onboarding debt shows the amber badge.
- [ ] Searching a pasted order number (full `Y-md-UUID8` or just the uuid8) finds the order; searching a contract's old number (contract-derived uuid8) also finds it; searching a customer name or email finds their orders. Search combines with the active filter.
- [ ] Pagination + chips preserve the search term; empty state renders.
- [ ] Export "Číslo objednávky" uses the canonical reference and respects filter + search.
- [ ] `composer quality` green; `composer test` green (list renders + search params).

## Out of scope

- **Per-order MRR / full financial breakdown** — the list shows debt/past-due flags; full finances live on the order detail and user detail (spec 065).
- **Severity gradation / days-overdue** on the list — a single "Po splatnosti" badge is enough here; the Po-splatnosti dashboard (spec 023) covers severity.
- **Sortable columns on the orders list** — not requested; only search + badges. (Could mirror spec 066 later.)
- **Sequential order numbers** — see spec 067 out-of-scope.

## Open questions

None — proceed.
