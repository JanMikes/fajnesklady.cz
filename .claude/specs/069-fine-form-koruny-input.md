# 069 — Fine form: accept money in koruny, not haléře

**Status:** done
**Type:** UX (validation/input tweak)
**Scope:** small (FineFormData, FineFormType, AdminFineCreateController, fine_form_controller.js, create template)
**Depends on:** none (aligns with spec 062's `inputmode` convention)

## Problem

Every customer/admin-facing money input in the app is entered in **koruny** (CZK) and converted to haléře on save — `StorageFormData`/`StorageTypeFormData` prices, `customMonthlyPriceInCzk`, `debtAmountInCzk`, `bankTransferSurchargeInCzk`, and the contract price-change input (`new_amount_czk`) are all `?float` koruny. The **one exception** is the **Fine create form** (`/portal/admin/orders/{id}` → fine creation), which makes the admin type **haléře**:

- `FineFormData::$amountInHaler` (int), `FineFormType` label "Částka (v haléřích)", `IntegerType`.
- `FineFormData::$latePaymentBaseInHaler` (int), label "Základ dluhu (v haléřích)".
- Template help text: "Částka v haléřích (100 = 1 Kč)." / "Dlužná částka v haléřích".
- `fine_form_controller.js` does its suggested-amount math in haléře (`600000`, `200000 * days`, `25000 * days`, then divides by 100 only for the live `= X Kč` display).

Thinking in haléře is error-prone (one extra zero = 10× the fine). The admin should type koruny and see koruny; haléře stays purely an internal storage unit.

## Goal

The fine form accepts and displays **koruny** (float, 2 decimals) everywhere the admin looks — amount and late-payment base inputs, suggested-amount auto-calculation, and the live preview. The value is converted to haléře at the controller boundary when dispatching `IssueFineCommand`. The `Fine` entity and `IssueFineCommand` keep storing haléře (`amountInHaler`) — only the input layer changes.

## Context (current state)

- `src/Form/FineFormData.php` — `?int $amountInHaler` (`#[Assert\Positive]`), `?int $latePaymentBaseInHaler`, `?int $nonReturnDays`, `?int $latePaymentDays`, `string $description`, `?FineType $type`.
- `src/Form/FineFormType.php:29-56` — `amountInHaler`/`latePaymentBaseInHaler` as `IntegerType` with `data-fine-form-target` attrs (`amount`, `latePaymentBase`) and `min: 1`.
- `src/Controller/Admin/AdminFineCreateController.php:47-56` — asserts `amountInHaler` not null, passes it straight into `new IssueFineCommand(amountInHaler: $formData->amountInHaler, …)`.
- `assets/controllers/fine_form_controller.js` — constants in haléře:
  - `dirty_storage` → `600000`; `non_return` → `200000 * days`; `late_payment` → `max(base * 0.0025 * days, 25000 * days)` (rounded haléře); `updateDisplay()` shows `(amount / 100)` Kč.
- `templates/admin/fine/create.html.twig:43-65` — labels via form, help texts mention "v haléřích".
- `IssueFineCommand` / `Fine` entity store `amountInHaler` (int) — **unchanged**.
- Fine constants in koruny: dirty storage **6 000 Kč**; non-return **2 000 Kč/den**; late payment **0,25 %/den** of base, min **250 Kč/den**.

## Requirements

### 1. FineFormData → koruny floats

Rename the two money fields and switch to float:
```php
#[Assert\NotBlank]
#[Assert\Positive]
public ?float $amountInCzk = null;

public ?float $latePaymentBaseInCzk = null;
```
Keep `nonReturnDays`, `latePaymentDays`, `type`, `description` as-is.

### 2. FineFormType → NumberType (koruny)

- `amountInCzk`: `NumberType` (`scale: 2`), label **"Částka (Kč)"**, attrs `{'data-fine-form-target' => 'amount', 'inputmode' => 'decimal', 'min' => 0.01, 'step' => '0.01'}`.
- `latePaymentBaseInCzk`: `NumberType` (`scale: 2`), label **"Základ dluhu (Kč)"**, attrs `{'data-fine-form-target' => 'latePaymentBase', 'inputmode' => 'decimal', 'min' => 0.01, 'step' => '0.01'}`.
Keep `nonReturnDays` / `latePaymentDays` as `IntegerType` (they're day counts, not money). Keep the Stimulus target names so the JS bindings stay valid.

### 3. Controller — convert at the boundary

`AdminFineCreateController`: assert `amountInCzk` not null, convert to haléře when dispatching:
```php
amountInHaler: (int) round($formData->amountInCzk * 100),
```
(`IssueFineCommand` signature unchanged.)

### 4. fine_form_controller.js → koruny math + display

- `typeChanged()` `dirty_storage`: `this.amountTarget.value = 6000;`
- `calculateAmount()`:
  - `non_return`: `this.amountTarget.value = 2000 * days;`
  - `late_payment`: `const percentCalc = base * 0.0025 * days;` (base now Kč) `const minCalc = 250 * days;` `this.amountTarget.value = round2(Math.max(percentCalc, minCalc));`
  - parse inputs with `parseFloat` (amount/base can be decimal), `parseInt` for day counts.
  - add a `round2(x)` helper → `Math.round(x * 100) / 100` so the field shows clean 2-decimal koruny.
- `updateDisplay()`: amount is already Kč — `= ${amount.toLocaleString('cs-CZ')} Kč` (drop the `/ 100`); parse with `parseFloat`.

### 5. Template help text → Kč

`templates/admin/fine/create.html.twig`: change "Dlužná částka v haléřích" → **"Dlužná částka v Kč"**, "Částka v haléřích (100 = 1 Kč)." → **"Částka v Kč."**. The `amountDisplay` preview (`= X Kč`) keeps working with the JS change.

## Acceptance

- [ ] On the fine form the admin types koruny (e.g. `6000` for the dirty-storage fine, not `600000`); the live preview reads "= 6 000 Kč".
- [ ] Selecting "dirty_storage" pre-fills `6000`; "non_return" with N days fills `2000·N`; "late_payment" with base (Kč) + days fills `max(0.25 %·base·days, 250·days)`, rounded to 2 decimals.
- [ ] Submitting persists the correct haléře value (`amountInCzk · 100`), verified on the resulting `Fine` and on the customer-facing fine views (which already format from haléře).
- [ ] Labels read "Částka (Kč)" / "Základ dluhu (Kč)"; no "haléř" wording remains on the form; PSČ-style numeric keypad (`inputmode=decimal`) on mobile.
- [ ] No change to `Fine` entity, `IssueFineCommand`, or fine display/export (still haléře internally).
- [ ] `composer quality` green; `composer test` green (fine creation maps koruny→haléře correctly).

## Out of scope

- **All other money inputs** — audited and already in koruny (`StorageFormData`, `StorageTypeFormData`, `customMonthlyPriceInCzk`, `debtAmountInCzk`, `bankTransferSurchargeInCzk`, contract `new_amount_czk`). Nothing to change there.
- **Changing internal storage to koruny.** The `Fine` entity, `IssueFineCommand`, `amountInHaler` columns, QR-payment route, and exports stay in haléře — only the input boundary converts.
- **Fine calculation policy** (rates, minimums) — values are mirrored from the existing JS, just expressed in koruny.

## Open questions

None — proceed.
