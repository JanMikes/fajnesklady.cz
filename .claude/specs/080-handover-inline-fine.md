# 080 — Issue a fine inline from the handover protocol (landlord + admin)

**Status:** ready
**Type:** UX / feature
**Scope:** medium (~11 files: form data + type, controller, 3 templates + 1 new partial, Stimulus controller, landlord order detail controller + template, handler message tweak, tests)
**Depends on:** none (builds on shipped specs 053 fines + 046 handover visibility)

## Problem

When a landlord or admin fills their side of the předávací protokol (check-out inspection), that is exactly the moment they discover a dirty or non-returned storage — i.e. the moment a smluvní pokuta per VOP §IV should be issued. Today they must finish the protocol, navigate to the admin order detail, and open a separate fine form (`/portal/admin/pokuty/vytvorit/{contractId}`) — and that page is `ROLE_ADMIN` only, so a landlord can't issue a fine at all. Multi-step, easy to forget, and the on-site person (landlord) is locked out entirely.

## Goal

The landlord/admin side of the handover protocol page gains an optional, collapsed "Vystavit smluvní pokutu" section — a copy of the existing fine form (type radio-driven auto-calculation, editable amount, mandatory note). Checking the box and submitting completes the protocol AND issues the fine in one step (fine-issued e-mail with payment link goes to the customer as today). **User decision: landlords may issue fines this way** (their only issuance path — the standalone admin fine page stays admin-only), and consequently landlords gain read-only visibility of fines on their order detail page.

## Context (current state)

- **Handover landlord/admin form**: `src/Controller/Portal/LandlordHandoverViewController.php` (`/portal/pronajimatel/predavaci-protokol/{id}`, `ROLE_LANDLORD`; admins pass via role hierarchy `config/packages/security.php:63` + voter short-circuit `src/Service/Security/HandoverProtocolVoter.php:40`). GET pre-fills a proposed lock code; POST dispatches `AddHandoverPhotoCommand` per photo, then `CompleteLandlordHandoverCommand` in a try/catch that unwraps `InvalidStorageCode` and re-renders (`LandlordHandoverViewController.php:60-100`). Form: `src/Form/LandlordHandoverFormData.php` (comment + newLockCode) + `LandlordHandoverFormType.php` (`csrf_protection => false`). Template: `templates/portal/landlord/handover/view.html.twig` — the fill form renders only when `protocol.landlordCompletedAt` is null and `canComplete` (lines 86-141), submit checks `needsLandlordCompletion()` server-side.
- **Fine form to copy**: `src/Form/FineFormData.php` + `FineFormType.php` (koruny floats per spec 069), template markup `templates/admin/fine/create.html.twig:26-72`, client auto-calc `assets/controllers/fine_form_controller.js` (targets `type/amount/nonReturnDays/nonReturnDaysWrapper/latePaymentBase/latePaymentDays/latePaymentWrapper/amountDisplay`; DIRTY_STORAGE → 6000, NON_RETURN → 2000×days, LATE_PAYMENT → max(base×0.25 %×days, 250×days)).
- **Fine issuance**: `App\Command\IssueFineCommand` / `IssueFineHandler` — creates `Fine`, assigns unique VS, audit-logs `fine/issued`, records `FineIssued` → `SendFineIssuedEmailHandler` e-mails the customer a payment link + QR bank details. `issuedById` is already a plain `User` lookup (handler just names the variable `$admin` and throws `'Admin user not found.'` — `IssueFineHandler.php:38-41`).
- **Admin read-only protocol view**: `src/Controller/Admin/AdminHandoverViewController.php` + `templates/admin/handover/view.html.twig` (spec 046).
- **Landlord fine visibility today: none.** `AdminFineListController` etc. are all `ROLE_ADMIN`; `templates/portal/landlord/**` has zero fine references. Landlord order detail: `src/Controller/Portal/LandlordOrderDetailController.php` + `templates/portal/landlord/order/detail.html.twig` (384 lines; "Smlouva" panel at ~line 204). Admin fines panel to mirror: `templates/admin/order/detail.html.twig:117-190` (`FineRepository::findByContract()` exists at `src/Repository/FineRepository.php:56`).
- **Fixtures**: `fixtures/HandoverProtocolFixtures.php` — `REF_HANDOVER_TENANT_COMPLETED` (on `REF_CONTRACT_TERMINATING`) is exactly the "landlord still owes their side" state. No FineFixtures exist; tests construct/persist `Fine` directly or dispatch `IssueFineCommand`.
- **Tests**: `tests/Integration/Controller/Portal/LandlordHandoverViewControllerTest.php` (has submit + wrong-landlord patterns), `tests/Unit/Form/AdminOnboardingFormDataTest.php` (callback-validation unit-test pattern with `violationsAt()` helper).
- **Gotcha (memory)**: conditional constraints must land at a field path, or the violation bubbles to the form root and yields a silent 422 — the handover template already includes `components/_form_root_errors.html.twig`, keep it.

## Requirements

### 1. `src/Form/LandlordHandoverFormData.php` — optional fine fields + callback validation

Flat fields (do NOT embed `FineFormData` — its `NotNull`/`NotBlank` constraints fire unconditionally and would break the no-fine submit; a `fine`-prefixed flat copy + callback mirrors the project's conditional-validation pattern, cf. `AdminOnboardingFormData::validateExternalIsPrepaid`):

```php
public bool $issueFine = false;

public ?FineType $fineType = null;
public ?float $fineAmountInCzk = null;
public ?int $fineNonReturnDays = null;
public ?float $fineLatePaymentBaseInCzk = null;
public ?int $fineLatePaymentDays = null;

#[Assert\Length(max: 2000)]
public string $fineDescription = '';

#[Assert\Callback]
public function validateFine(ExecutionContextInterface $context): void
{
    if (!$this->issueFine) {
        return;
    }

    if (null === $this->fineType) {
        $context->buildViolation('Vyberte typ pokuty.')->atPath('fineType')->addViolation();
    }
    if (null === $this->fineAmountInCzk || $this->fineAmountInCzk <= 0) {
        $context->buildViolation('Zadejte kladnou částku pokuty.')->atPath('fineAmountInCzk')->addViolation();
    }
    if ('' === trim($this->fineDescription)) {
        $context->buildViolation('Zadejte popis pokuty.')->atPath('fineDescription')->addViolation();
    }
}
```

When `issueFine` is false, fine field values are ignored entirely (no validation, no dispatch).

### 2. `src/Form/LandlordHandoverFormType.php` — add the fields

`issueFine` as `CheckboxType` (`label => 'Vystavit smluvní pokutu'`, `required => false`), then the five fine inputs copying `FineFormType.php:24-74` widget config verbatim (same labels, `scale`, `inputmode`, `data-fine-form-target` attrs, `placeholder => 'Vyberte typ pokuty'`), all `required => false` at the form level (requiredness is the callback's job). `fineDescription` as `TextareaType` with `label => 'Popis / poznámka (vidí zákazník)'`, `empty_data => ''`.

### 3. Shared partial `templates/admin/fine/_fine_fields.html.twig` + refactor `create.html.twig`

Extract the six field blocks from `templates/admin/fine/create.html.twig:27-72` byte-identical (keep the existing hand-rolled widget classes — predates the `.form-input` rule, do not restyle) into a partial parameterised by form fields so both callers work despite different child names:

```twig
{# expects: type, nonReturnDays, latePaymentBase, latePaymentDays, amount, description (form fields) #}
```

- `create.html.twig` includes it `with {type: form.type, nonReturnDays: form.nonReturnDays, latePaymentBase: form.latePaymentBaseInCzk, latePaymentDays: form.latePaymentDays, amount: form.amountInCzk, description: form.description} only` — page must render pixel-identical.
- Handover template includes it with the `fine*`-prefixed fields.

### 4. `templates/portal/landlord/handover/view.html.twig` — fine section inside the fill form

Inside the existing `{% elseif canComplete %}` form (after the lock-code block, before submit), add a bordered section:

```twig
<div class="border-t border-gray-200 pt-6" data-controller="fine-form">
    <label class="flex items-center gap-2">
        {{ form_widget(form.issueFine, {attr: {'data-fine-form-target': 'toggle', 'data-action': 'change->fine-form#toggleSection'}}) }}
        <span class="text-sm font-medium text-gray-900">Vystavit smluvní pokutu</span>
    </label>
    <p class="mt-1 text-xs text-gray-500">Pokuta bude zákazníkovi vystavena společně s potvrzením převzetí a e-mailem mu přijde výzva k úhradě.</p>

    <div data-fine-form-target="section" class="mt-4 space-y-6 {{ form.issueFine.vars.checked ? '' : 'hidden' }}">
        {% include 'admin/fine/_fine_fields.html.twig' with {type: form.fineType, nonReturnDays: form.fineNonReturnDays, latePaymentBase: form.fineLatePaymentBaseInCzk, latePaymentDays: form.fineLatePaymentDays, amount: form.fineAmountInCzk, description: form.fineDescription} only %}
    </div>
</div>
```

The `vars.checked` fallback keeps the section open when server-side validation fails and the page re-renders. Submit button label stays `Potvrdit převzetí skladu`; no JS confirm (fines are cancellable by admin).

### 5. `assets/controllers/fine_form_controller.js` — optional toggle

Add `'toggle', 'section'` to `static targets` and:

```js
toggleSection() {
    if (this.hasSectionTarget) {
        this.sectionTarget.classList.toggle('hidden', !this.toggleTarget.checked);
    }
}
```

Guarded by `hasSectionTarget`, so the admin create page (no toggle) is unaffected.

### 6. `src/Controller/Portal/LandlordHandoverViewController.php` — dispatch the fine

After the successful `CompleteLandlordHandoverCommand` dispatch (order matters: completion first — if `InvalidStorageCode` throws, no fine is issued and the re-rendered form preserves the submitted fine values):

```php
if ($formData->issueFine) {
    /** @var \App\Entity\User $user */
    $user = $this->getUser();
    assert(null !== $formData->fineType);
    assert(null !== $formData->fineAmountInCzk);

    $this->commandBus->dispatch(new IssueFineCommand(
        contractId: $contract->id,
        type: $formData->fineType,
        amountInHaler: (int) round($formData->fineAmountInCzk * 100),
        description: $formData->fineDescription,
        issuedById: $user->id,
    ));
    $this->addFlash('success', 'Pokuta vystavena');
}
```

Security containment (deliberate, per user decision): a landlord's ONLY issuance path is this one, gated by the same `COMPLETE_LANDLORD` voter grant (owner of the storage, protocol still pending). `AdminFineCreateController` stays `ROLE_ADMIN` — do not touch its guard.

### 7. `src/Command/IssueFineHandler.php` — de-adminify wording

Rename local `$admin` → `$issuedBy` and the exception message `'Admin user not found.'` → `'Issuing user not found.'` (`IssueFineHandler.php:38-41`). No behavioral change; the command signature already takes any user id.

### 8. Landlord read-only fines panel

- `src/Controller/Portal/LandlordOrderDetailController.php`: inject `FineRepository`, pass `'fines' => null !== $contract ? $this->fineRepository->findByContract($contract) : []`.
- `templates/portal/landlord/order/detail.html.twig`: after the "Smlouva" panel (~line 234), add a "Smluvní pokuty" card rendered **only when `fines|length > 0`** (landlords have no create button here, an empty panel is noise). Mirror the admin table (`templates/admin/order/detail.html.twig:130-172`) columns Datum / Typ / Částka / Stav / Způsob platby / Poznámka — **no Akce column** (cancel stays admin-only). Czech labels with diacritics; amount via `(fine.amountInHaler / 100)|number_format(0, ',', ' ')` Kč.

### 9. `templates/admin/handover/view.html.twig` — post-completion shortcut

Add a small `Vystavit pokutu` link-button (`btn btn-secondary btn-sm`, `path('admin_fine_create', {contractId: contract.id})`) in the header area, so an admin reviewing an already-completed protocol (dirt discovered later) reaches the classic form in one click. The inline fine section itself lives only in the pending fill form.

### 10. Tests

- **Unit** `tests/Unit/Form/LandlordHandoverFormDataTest.php` (mirror `AdminOnboardingFormDataTest` validator/`violationsAt` pattern): valid no-fine data passes; `issueFine=true` with null type / non-positive amount / blank description yields violations at `fineType` / `fineAmountInCzk` / `fineDescription`; `issueFine=false` with garbage fine fields passes.
- **Integration** extend `tests/Integration/Controller/Portal/LandlordHandoverViewControllerTest.php`:
  1. Landlord submits completion with `issueFine` checked + valid fields → redirect, protocol landlord-completed, exactly one `Fine` row for the contract with expected `type`, `amountInHaler` (koruny→haléře conversion), `description`, `issuedBy` = the landlord, and a non-null `variableSymbol`.
  2. `issueFine` checked, missing type/amount/description → 422 re-render, protocol NOT completed, zero `Fine` rows.
  3. `issueFine` unchecked (fine fields empty) → completes as before, zero `Fine` rows.
  Use `REF_HANDOVER_TENANT_COMPLETED` / the existing test's contract-lookup helpers; the storage owner landlord must be the authenticated user.
- **Integration** landlord order detail: persist a `Fine` for the contract (construct entity directly — no FineFixtures exist), assert the landlord sees "Smluvní pokuty" + the amount; assert the panel is absent when no fines.

## Acceptance

- [ ] On `/portal/pronajimatel/predavaci-protokol/{id}` with a pending landlord side, both a landlord (storage owner) and an admin see the collapsed "Vystavit smluvní pokutu" checkbox; checking it reveals the fine fields; type selection auto-fills amount (6000 for znečištění) exactly like the admin fine page.
- [ ] Submitting with the box checked completes the protocol and creates the fine in one POST; two success flashes; customer receives the fine-issued e-mail with payment link (existing `FineIssued` flow); audit log has both `handover/landlord_submitted` and `fine/issued`.
- [ ] Validation failure on fine fields keeps the section expanded, shows Czech errors at the fields, and does NOT complete the protocol.
- [ ] `InvalidStorageCode` path still re-renders with the lock-code error and issues no fine.
- [ ] Admin fine create page (`/portal/admin/pokuty/vytvorit/{contractId}`) renders identically after the partial extraction and stays `ROLE_ADMIN`.
- [ ] Landlord order detail shows the read-only "Smluvní pokuty" panel when fines exist; no cancel/create actions for landlords anywhere.
- [ ] Admin read-only handover view has the `Vystavit pokutu` shortcut link.
- [ ] `composer quality` green; full `composer test` green (controller/template changes — integration tests must run).

## Out of scope

- Landlord fine list page / sidebar entry — order-detail panel is enough for v1; expand later if landlords ask.
- Landlord cancel/reminder rights — cancel (`AdminFineCancelController`) and manual reminder stay admin-only.
- Admin e-mail notification when a landlord issues a fine — admins already see new unpaid fines via the sidebar fines badge + operations hub (spec 053); audit log records the issuer.
- Multiple fines in one handover submit — one optional fine, matching the single-fine admin form; extras via the classic page.
- Fine section on the tenant side (`/predavaci-protokol/{id}` public signed view) — tenants never issue fines.
- Complete-on-behalf from the admin read-only view — unchanged from spec 046's out-of-scope.

## Open questions

None — proceed.
