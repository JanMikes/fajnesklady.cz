# 090 — Fix variable symbols that banks strip to a different value (leading zero)

**Status:** done
**Type:** bugfix
**Scope:** small (~6 files + tests + one-off prod runbook)
**Depends on:** none. **Blocks 089** — see "Ordering" below.

## Problem

`VariableSymbolGenerator::computeVs()` left-pads every generated symbol to 10 digits:

```php
// src/Service/Payment/VariableSymbolGenerator.php:55
return str_pad((string) abs($hash % 10_000_000_000), 10, '0', STR_PAD_LEFT);
```

**Measured 2026-07-21 (20 000 seeds through the current `computeVs()`): 23.6% of generated symbols start with `0`** — not "roughly one in ten" as first estimated. `crc32()` returns `0..4294967295`, and every value below `1_000_000_000` (≈23.3% of that range) gets left-padded. The ČNB/ČBA recommendation *Zadávání variabilního, konstantního a specifického symbolu* defines the variable symbol as "**jedno až desetimístné číslo**" — a one-to-ten-digit **number**, not a string. A number has no leading zeros, so the bank does not preserve ours. `0451060965` arrives from FIO as `451060965`.

Both matcher lookups compare with exact string equality:

```php
// src/Repository/OrderRepository.php:697-706  (and FineRepository.php:31 identically)
->where('o.variableSymbol = :vs')
```

`'451060965' !== '0451060965'` → no order found → the transfer falls through `attemptAutoMatch()` and sits in `/portal/admin/bankovni-platby` as `unmatched` forever. The admin page offers no pairing action (spec 078 deferred it), so the money is stuck.

We compound the problem ourselves: `QrPaymentGenerator.php:53` does `$payment->setVariableSymbol((int) $variableSymbol)`, so the QR code **we** generate already carries the unpadded `451060965`. The customer scans our code, pays exactly what we asked, and we then fail to recognise it. This is entirely our defect — not a bank quirk and not customer error.

**Live incident.** `bank_transaction` `019f842c-a03a-783f-ab51-fd56326c139c` — 3 100 Kč, VS `451060965`, FIO id `27749499743`, received 2026‑07‑21 — belongs to order `019f7f7b-9ab4-7b49-925e-af33888d39cf` (`pn6873goog@gmail.com`, `total_price = 310000`, `status = reserved`, `bank_transfer` / `manual_recurring`, VS `0451060965`). The amount matches to the haléř; only the VS comparison failed.

**Blast radius (verified against prod 2026‑07‑21):** 13 orders carry a VS, **3 of them start with `0`** — `0451060965`, `0380094939`, `0182260897`. The latter two were spared only because they were settled manually as `external`. A further **15 orders have `variable_symbol IS NULL`** and will be assigned symbols by spec 089's backfill — at the measured 23.6% rate, ~4 of those would be born broken if 089 deployed first.

**089 amplifies this from a subset to everything.** Before 089 only `BANK_TRANSFER` orders were issued a symbol. 089 assigns one to *every* order at creation, so without this fix roughly one order in four would carry a symbol the bank mangles — including card orders whose customers may later want to wire a debt (which is 089's whole point).

## Goal

A variable symbol we issue is one the bank will hand back to us unchanged, and a variable symbol we receive is matched against the orders and fines we already issued regardless of leading-zero formatting. After this spec the stuck 3 100 Kč transfer reconciles itself on the next cron run, and no future order can be issued a symbol the bank will mangle.

## Context (current state)

**Generation**
- `src/Service/Payment/VariableSymbolGenerator.php` — `generate(Uuid $orderId)` `:19`, `generateForFine(Uuid $fineId)` `:35`, `computeVs()` `:51-56`, uniqueness probes `existsInOrders()` `:58` / `existsInFines()` `:69`. Deterministic per id; the retry loop appends `-{attempt}` (max 10).
- `crc32()` in PHP on 64-bit returns an int in `[0, 4294967295]`, so `abs()` at `:55` is already a no-op. Worth simplifying while we're here.

**Storage / validation — all confirmed correct at 10, do not change**
- `src/Entity/Order.php:91-92` — `#[ORM\Column(length: 10, nullable: true, unique: true)]`, setter `assignVariableSymbol()` `:528`.
- `src/Entity/Fine.php:21` — same column, setter `:120`.
- `src/Entity/BankTransaction.php:52-53` — `#[ORM\Column(length: 10, nullable: true)] private(set) ?string $variableSymbol`.
- `src/Form/AdminOnboardingFormData.php:112-114` — `#[Assert\Length(max: 10)]` + `#[Assert\Regex('/^\d*$/')]`.

**Lookup**
- `src/Repository/OrderRepository.php:697-706` and `src/Repository/FineRepository.php:31-41` — exact equality, the defect.
- `src/Command/ProcessIncomingBankTransactionHandler.php:86-87` — guards `null !== $variableSymbol && '' !== $variableSymbol` before calling `findByVariableSymbol()`; fine lookup at `:115`.

**Ingestion** — `src/Service/Payment/FioClient.php:29-51` passes `$t->getVariableSymbol()` straight through, unnormalised.

**Cron** — `src/Console/ProcessFioTransactionsCommand.php` (`app:process-fio-transactions`). Window is `now-3 days → now`; dedupe is `BankTransactionRepository::existsByFioTransactionId()` backed by a unique index, so a plain re-run **skips** already-ingested rows. This matters for the runbook below.

**Collision safety — verified, not assumed.** Run against prod on 2026‑07‑21, over `orders` ∪ `fine`:

```sql
WITH allvs AS (
  SELECT variable_symbol AS vs FROM orders WHERE variable_symbol IS NOT NULL
  UNION ALL SELECT variable_symbol FROM fine WHERE variable_symbol IS NOT NULL)
SELECT ltrim(vs,'0'), count(*) FROM allvs GROUP BY 1 HAVING count(*) > 1;
```

→ **0 rows.** Normalisation merges no two existing symbols. It also cannot create a future collision: post-fix symbols are always exactly 10 chars starting `1`–`9`, whereas a normalised legacy `0…` symbol is at most 9 chars, so the two sets are disjoint by length.

**Conventions** — `CLAUDE.md`: no migration needed (no schema change); `composer quality` does **not** run integration tests, so run `composer test`.

## Ordering — read before scheduling

Spec **089** adds `app:backfill-variable-symbols`, which assigns a symbol to all 15 `variable_symbol IS NULL` orders using `computeVs()`, and additionally assigns one to every newly created order. **This fix must be live before that backfill runs**, otherwise it mints a fresh batch of leading-zero symbols that are broken from birth.

**Status as of implementation (2026‑07‑21):** 089 is already implemented locally but not yet deployed, so the ordering constraint is satisfied by committing 090 *before* 089 and pushing them together — the corrected generator ships in the same release. 089 needs no code edits of its own; it calls `generate()` and inherits the fix.

**Deploy-time requirement:** `app:backfill-variable-symbols` must be run **after** the release containing this spec is live. Running it against the old generator is the failure mode this ordering exists to prevent.

089's prose describing `computeVs()` as `str_pad(...)` goes stale on merge; that is descriptive context, not a requirement.

## Requirements

### 1. `src/Service/Payment/VariableSymbolGenerator.php` — never mint a leading zero

Replace `computeVs()`:

```php
private function computeVs(string $input): string
{
    // The variable symbol is a NUMBER per the ČNB/ČBA recommendation ("jedno až
    // desetimístné číslo"), so banks do not preserve leading zeros — a padded
    // "0451060965" comes back from FIO as "451060965" and no longer matches the
    // order. Keep the fixed 10-digit width (invoices, QR codes and e-mails all
    // render it) but force the first digit into 1-9 so the value survives the
    // round trip unchanged. crc32() yields 0..4294967295, so the result lands in
    // [1000000000, 5294967295] — always exactly 10 digits.
    return (string) (1_000_000_000 + crc32($input) % 9_000_000_000);
}
```

Drop the now-redundant `abs()`. Width stays 10, so `Order.variableSymbol` / `Fine.variableSymbol` `length: 10` and the `Assert\Length(max: 10)` are all still exactly right — **do not widen or narrow any column or constraint.**

### 2. Shared normalisation helper

Both repositories and the matcher need the same rule. Add a small static helper rather than duplicating `ltrim` in four places — put it on the generator, which already owns variable-symbol semantics:

```php
/**
 * Strip leading zeros so a symbol we issued matches the one the bank hands
 * back. Returns null when nothing meaningful remains (null, "", "0000000000").
 */
public static function normalize(?string $variableSymbol): ?string
{
    if (null === $variableSymbol) {
        return null;
    }

    $normalized = ltrim(trim($variableSymbol), '0');

    return '' === $normalized ? null : $normalized;
}
```

`trim()` first — FIO has been observed returning padded/whitespaced fields, and it costs nothing.

### 3. Zero-insensitive lookups

`src/Repository/OrderRepository.php:697` and `src/Repository/FineRepository.php:31` both become:

```php
public function findByVariableSymbol(string $variableSymbol): ?Order
{
    $normalized = VariableSymbolGenerator::normalize($variableSymbol);
    if (null === $normalized) {
        return null;
    }

    return $this->entityManager->createQueryBuilder()
        ->select('o')
        ->from(Order::class, 'o')
        // Compare numerically-equivalent symbols: we historically issued
        // zero-padded values that the bank returns unpadded (spec 090).
        ->where("TRIM(LEADING '0' FROM o.variableSymbol) = :vs")
        ->setParameter('vs', $normalized)
        ->getQuery()
        ->getOneOrNullResult();
}
```

`TRIM(LEADING '0' FROM x)` is standard DQL (`TrimExpression` in the Doctrine grammar) and needs no custom function. The comparison is not index-backed, but these tables are tiny (28 orders) and the call sites are a 5-minute cron plus admin pages — do **not** add a generated column or index for this.

`getOneOrNullResult()` throws `NonUniqueResultException` if normalisation ever merged two rows. Prod is verified clean and the disjoint-length argument above shows new symbols cannot collide with legacy ones, so let it throw rather than silently picking one — a surprise here is a data-integrity bug we want loud.

### 4. Normalised uniqueness probe

`existsInOrders()` `:58` / `existsInFines()` `:69` must use the same normalised comparison, so the generator can never hand out a symbol that is numerically equal to an existing one:

```php
->where("TRIM(LEADING '0' FROM o.variableSymbol) = :vs")
->setParameter('vs', VariableSymbolGenerator::normalize($vs))
```

### 5. Normalise on ingestion

`src/Service/Payment/FioClient.php:29-51` — store the normalised form so the `bank_transaction` row, the admin table, audit payloads and mismatch e-mails all show the same value the bank actually sent:

```php
variableSymbol: VariableSymbolGenerator::normalize($t->getVariableSymbol()),
```

This is belt-and-braces (requirement 3 already handles an unnormalised needle) but keeps stored data canonical.

### 6. Do not touch the three existing `0…` orders

`0451060965`, `0380094939`, `0182260897` **keep their stored symbols.** Customers have paid against them, and the QR codes and e-mails are already out; rewriting them would invalidate a payment instruction that is in the wild. Requirement 3 is what makes them work. No data migration, no `UPDATE`.

### 7. Prod runbook for the stuck transfer (post-deploy, one-off)

The VS fix does not retroactively re-match an already-ingested row: `attemptAutoMatch()` runs once at ingestion, and `existsByFioTransactionId()` makes a re-run skip it. Because the transfer is dated 2026‑07‑21 it is still inside the cron's `now-3 days` window, so deleting the unmatched row lets the next run re-ingest and match it properly — no manual pairing needed.

**This is time-sensitive: it only works while the transaction date is within 3 days.** If the window has passed by deploy time, leave the row alone and resolve it with spec 091's pairing UI instead.

```bash
# 1. deploy this spec first and confirm the new code is live

# 2. verify the row is still unmatched and unreferenced
ssh root@lily.srv.thedevs.cz "docker exec -i fajnesklady-db-1 psql -U fajneskladypostgres -d fajnesklady" <<'SQL'
SELECT id, status, paired_order_id, variable_symbol, amount, transaction_date
FROM bank_transaction WHERE fio_transaction_id = '27749499743';
SQL

# 3. only if status = 'unmatched' AND paired_order_id IS NULL, delete it
ssh root@lily.srv.thedevs.cz "docker exec -i fajnesklady-db-1 psql -U fajneskladypostgres -d fajnesklady" <<'SQL'
DELETE FROM bank_transaction
WHERE fio_transaction_id = '27749499743' AND status = 'unmatched' AND paired_order_id IS NULL;
SQL

# 4. re-run the importer
ssh root@lily.srv.thedevs.cz "docker exec fajnesklady-web-1 bin/console app:process-fio-transactions"

# 5. confirm it paired to order 019f7f7b-9ab4-7b49-925e-af33888d39cf
```

An `unmatched` row owns no `Payment` rows and is referenced by nothing, so the delete is safe — but re-check step 2 rather than trusting this sentence. Deleting a `matched` row would strand real payment history.

Expect the re-run to route through `matchToOrder()` → the `usesManualBillingTrack()` branch (`ProcessIncomingBankTransactionHandler.php:253`), since the order is `manual_recurring`. If `RecurringAmountCalculator::calculate()` returns something other than 310000 the row will land in `amount_mismatch` instead of `matched` — that is correct behaviour, not a regression, and is 091's territory.

### 8. Tests

- **Unit, new** `tests/Unit/Service/Payment/VariableSymbolGeneratorTest.php` (or extend if present): for a spread of seed uuids the generated symbol is exactly 10 chars, `ctype_digit`, and `$vs[0] !== '0'`. Assert `normalize()` directly: `'0451060965' → '451060965'`, `'451060965' → '451060965'`, `'  0012  ' → '12'`, `'0000000000' → null`, `'' → null`, `null → null`.
- **Integration, extend** the FIO matcher tests: an order with a stored VS of `0451060965` receiving a FIO transaction with VS `451060965` pairs to that order. This is the regression test for the incident — without it the bug silently returns.
- **Integration** the reverse direction: stored `451060965`, incoming `0451060965`, also pairs.
- Fines get the same round-trip coverage as orders.

## Acceptance

- [ ] `VariableSymbolGenerator::generate()` and `generateForFine()` return exactly 10 digits with a first digit in `1`–`9`, for every seed tried.
- [ ] A FIO transaction with VS `451060965` pairs to an order stored as `0451060965`.
- [ ] A FIO transaction with VS `0451060965` pairs to an order stored as `451060965`.
- [ ] The same holds for `Fine` lookups.
- [ ] The three existing `0…` order symbols are byte-identical in the DB after deploy.
- [ ] `docker compose exec web bin/console doctrine:schema:validate` is green (no schema change expected).
- [ ] `composer quality` is green.
- [ ] `composer test` is green (integration tests do not run under `composer quality`).
- [ ] **Post-deploy:** bank transaction `27749499743` shows `matched` against order `019f7f7b-9ab4-7b49-925e-af33888d39cf`, or `amount_mismatch` against it — either proves the VS lookup now works.

## Out of scope

- **Manual pairing UI.** Spec 091. This spec deliberately ships without it so the bug fix is not gated on a feature.
- **Rewriting the three legacy `0…` symbols.** Requirement 6 — they are live payment instructions.
- **Widening or narrowing the 10-char columns / `Assert\Length(max: 10)`.** Ten is the ČNB maximum and is confirmed correct.
- **Backfilling the 15 NULL-VS orders.** That is spec 089, which must land *after* this one.
- **Indexing the normalised comparison.** Tables are tiny; a generated column would be premature.
- **Specific symbol (`SS`) / constant symbol (`KS`).** We do not issue them.
- **Re-matching historical `unmatched`/`ignored` rows in bulk.** Only the single live incident is in scope, via the requirement 7 runbook.

## Open questions

None — proceed.
