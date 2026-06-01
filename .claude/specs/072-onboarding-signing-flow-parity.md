# 072 — Onboarding signing page: parity with the order flow (contract display, preview, contextual logos, recurring consent)

**Status:** done
**Type:** UX + bug-fix bundle + compliance parity
**Scope:** medium (~10 files: 1 new shared contract partial + refactor `order_accept.html.twig` to use it, rewrite `customer_signing.html.twig`, extend `CustomerSigningController`, 1 new preview controller + route, 1 small `Order` helper, add 2 modals, tests, PROJECT_MAP/BACKLOG/COMPLIANCE updates)
**Depends on:** spec 050 (unified admin onboarding → every onboarding signs VOP via `/podpis/{token}`, optional uploaded contract), spec 043 (`CustomerBillingSituation`, `SigningPriceViewModel`), spec 070 (`form-guard` controller, submit-token / consent wiring), spec 016 + `.claude/COMPLIANCE.md` (recurring consent, logos, § 1826a)

## Problem

After spec 050 every admin-created onboarding routes the customer through the same signing page (`/podpis/{token}`, `templates/public/customer_signing.html.twig`). That page has drifted badly from the public order-accept page (`/prijmout`, `templates/public/order_accept.html.twig`) it is supposed to mirror, and is broken in both onboarding scenarios:

1. **Scenario A — admin pre-uploaded a contract (`order.hasUploadedContract()` is true).** The customer is *not* signing a new contract — the contract was already agreed on paper and uploaded by the admin. The customer is only accepting + signing the **new VOP**. But the page is hard-titled **"Podpis smlouvy"** (`customer_signing.html.twig:11,42`), which is misleading, and the uploaded file is only *mentioned* in a green banner ("Vaše smlouva byla nahrána administrátorem", `:44-49`) — it is **never shown**. The customer signs a contract they can't see.

2. **Scenario B — no contract uploaded.** Here the customer genuinely *is* signing the contract, so "Podpis smlouvy" is correct — but the page **never renders the contract text**. The full "Smlouva o nájmu" (Pronajímatel / Nájemce / Articles I–VI) lives only inline in `order_accept.html.twig:184-249`. On the signing page the customer signs a contract whose terms are nowhere on the page. The order flow shows it; onboarding doesn't. Bug.

3. **Payment logos are absent and non-contextual.** The order flow shows the card / 3D-Secure / GoPay logos + the SSL/3DS note, gated to card payments (`order_accept.html.twig:522` → `!= 'bank_transfer'`). The signing page shows none. The desired rule: show the logos **only when the customer will actually pay by card (GoPay) through us**; hide them for bank transfer, externally-prepaid, and free onboardings.

4. **No recurring-payment consent.** For an AUTO_RECURRING card onboarding the customer sets up a stored-card recurring payment exactly like the order flow — but the signing page omits the "Parametry opakované platby" card, the dedicated "Souhlasím s opakovanou platbou" checkbox, and the PCI-DSS disclosure that GoPay/`.claude/COMPLIANCE.md` require. That's a compliance gap. The order flow has all of it (`order_accept.html.twig:399-519`).

5. **Consent set is thinner than the order flow.** Signing collects only VOP + GDPR + e-signature (+ contract). The order flow additionally collects poučení spotřebitele (consumer notice), provozní řád (operating rules, when the place has one), and the § 1826a 14-day withdrawal waiver. Same customer, same legal exposure — the consents should match.

## Goal

The onboarding signing page becomes a faithful, situation-aware sibling of `order_accept.html.twig`. Concretely:

- **Scenario B (fresh contract):** renders the *identical* contract text the order flow shows (via a shared partial), plus the full consent bundle, the recurring card, and contextual logos. The customer signs a contract they can read.
- **Scenario A (uploaded contract):** retitled to **"Přijetí … obchodních podmínek"** (the customer is accepting + signing the new VOP, not signing a contract); the uploaded file is **previewed inline**; the consent is VOP-centric (no inline contract).
- **Both:** payment logos + recurring consent appear **only** when the customer will pay by card (GoPay first charge, AUTO_RECURRING); bank-transfer / prepaid / free onboardings hide them. Consumer-notice + operating-rules consent are added for both. The 14-day withdrawal waiver is added for **scenario B only**, and only when the start date is within 14 days. The submit button stays signing-focused (never `OBJEDNÁVÁM a zaplatím`, because clicking it never charges — GoPay payment is a separate page).

## Context (current state)

### The two pages

- **Reference (gold):** `templates/public/order_accept.html.twig` — full contract text (`:184-249`), single consolidated consent (`:356-397`), MANUAL info card (`:405-418`), AUTO recurring params + dedicated consent + PCI-DSS (`:433-520`), contextual logos (`:522-533`), § 1826a disclosure + binding button (`:535-565`). Driven by `OrderAcceptController` (`src/Controller/Public/OrderAcceptController.php`), which passes `paymentSchedule`, `isRecurring`, `requiresEarlyStartWaiver`, `recurringPaymentLegalMaxInCzk`, `submitted`, `now`.
- **To fix:** `templates/public/customer_signing.html.twig` + `src/Controller/Public/CustomerSigningController.php`. The controller is single-action; `handlePost()` already validates `accept_contract` (conditional on `hasUploadedContract()`), `accept_vop`, `accept_gdpr`, `signature_data`, `signing_place`, `signature_consent`, `signing_method` (`:78-101`). It builds `SigningPriceViewModel::fromOrder($order)` for the price banner (spec 043). **Note:** spec 043 dropped the `PriceCalculator` dep from this controller — it must be re-injected.

### Onboarding billing shape (drives all the gating)

- `Order.billingMode` (`Order.php:102-103`, enum `BillingMode`, default `AUTO_RECURRING`), `Order.paymentMethod`, `Order.isRecurring()` (`:346`), `Order.hasUploadedContract()` (`:473`), `Order.hasUnpaidDebt()` (`:483`), `Order.firstPaymentPrice` (haléře, `:192`) / `Order.getFirstPaymentPriceInCzk()` (`:365`), `Order.isUnlimited()` (`:331`), `Order.startDate` / `Order.endDate`.
- **`CustomerBillingSituation::fromOrder(Order)`** (`src/Service/Order/CustomerBillingSituation.php`): `FREE` (individualMonthlyAmount === 0) → `EXTERNALLY_PREPAID` (paidThroughDate set) → else `GOPAY_FIRST_CHARGE`. **`GOPAY_FIRST_CHARGE` means "customer pays the first charge through us" — this includes BANK_TRANSFER, not just GoPay.** The enum name is historical; treat it as "pay flow".
- Per `AdminOnboardingFormData` + `AdminOnboardingHandler.php:70-74`: free/prepaid (and no debt) force `paymentMethod = EXTERNAL`; BANK_TRANSFER forces `billingMode = MANUAL_RECURRING`; UNLIMITED forces `AUTO_RECURRING`; YEARLY forces `MANUAL_RECURRING`. **`billingMode` is NOT reset for free/prepaid**, so a free/prepaid UNLIMITED order still carries `AUTO_RECURRING` — which is why recurring-consent gating must also check the situation, not just `billingMode`.
- **`PriceCalculator::buildScheduleFromOrder(Order): PaymentSchedule`** (`src/Service/PriceCalculator.php:322`) — builds the schedule from `Order.firstPaymentPrice` (the locked-in monthly, **respecting admin overrides**), NOT the current storage rate. This is the correct source for the recurring-params card on the signing page. `PaymentSchedule` exposes `isOpenEnded`, `getMonthlyAmountInCzk()` (Twig: `.monthlyAmountInCzk`), `entryCount()` (`.entryCount`). `PriceCalculator::MAX_RECURRING_PAYMENT_AMOUNT_IN_HALER = 1_500_000`.

### Uploaded contract storage (for the scenario-A preview)

- Stored by `AdminOnboardingHandler::moveContractDocument()` (`:113-126`) to an **absolute path** under `%kernel.project_dir%/var/contracts/contract_{orderId}.{ext}`, persisted on `Order.uploadedContractDocumentPath` (`Order.php:161-162`). Extension is the original upload's (`pdf` / `jpg` / `jpeg` / `png` — `AdminOnboardingFormData.php:96-101` restricts MIME to `application/pdf`, `image/jpeg`, `image/png`).
- The file is **not** under `public/` and the customer is passwordless (created by onboarding). It must be served by a **signing-token-gated** route. Mirror the path-safety check in `OrderContractDownloadController.php:53-63` (resolve `realpath`, assert it is inside the contracts dir).

### User entity fields the contract partial needs

`User` (`src/Entity/User.php`) exposes `fullName` (`:86`), `email`, `firstName`, `lastName`, `phone`, `birthDate` (`:57`), and billing fields `companyName` (`:39`), `companyId`, `companyVatId`, `billingStreet`, `billingCity`, `billingPostalCode`. `StorageType` exposes `innerWidth` / `innerHeight` / `innerLength` (cm, `:59-63`) and `name`.

### Reusables already present

- `templates/components/payment_logos.html.twig`, `templates/components/lock_disclaimer.html.twig`, `templates/public/_term_modal.html.twig`, `_terms_and_conditions_content.html.twig` (VOP), `_privacy_policy_content.html.twig` (GDPR), `_consumer_notice_content.html.twig`, `_recurring_payments_terms_content.html.twig`.
- Stimulus: `signature_controller.js`, `form_guard_controller.js` (both already wired on the signing page). The signing page's Alpine root currently exposes `{ signed, signingPlace, acceptAll }` — extend with `acceptRecurring` (mirror `order_accept.html.twig:44-49`).
- `upload_url()` Twig function (`src/Twig/UploadExtension.php`) for the operating-rules link.

## Architecture

```
                         /podpis/{token}  (CustomerSigningController)
                                   │
             ┌─────────────────────┴───────────────────────┐
             │  order.hasUploadedContract() ?               │
             ▼ no (Scenario B)                              ▼ yes (Scenario A)
   title "Podpis smlouvy"                          title "Přijetí … obchodních podmínek"
   render _contract_terms.html.twig  ◄── shared ──► render uploaded-file PREVIEW
   (identical to order_accept)         partial      (new token-gated route /podpis/{token}/smlouva)
   consent bullet: "smluvními                       consent bullet: "smlouvou … (zobrazenou výše)"
   podmínkami uvedenými výše"                        accept_contract auto-set (paper)
             │                                              │
             └─────────────────────┬───────────────────────┘
                                   ▼  (both, gated on situation/method/billingMode)
   situation = CustomerBillingSituation::fromOrder(order)     [PAY | PREPAID | FREE]
     PAY + AUTO_RECURRING + isRecurring → "Parametry opakované platby" + dedicated consent + PCI-DSS
     PAY + MANUAL_RECURRING + isRecurring → "Ručně schvalovaná platba" info card
     PAY + paymentMethod GOPAY            → payment logos + SSL/3DS note
     PREPAID / FREE                       → green banner (existing), no payment UI
   + consumer-notice consent (always)  + operating-rules consent (place has rules)
   + early-start waiver (Scenario B only, start ≤ now+14d)
   submit: signing-focused label (never "OBJEDNÁVÁM a zaplatím")
```

## Requirements

### 1. Extract the contract text into a shared partial

New `templates/public/_contract_terms.html.twig` — move the contract markup currently inline at `order_accept.html.twig:184-249` (the `<div class="border … bg-white">` … `<h3>Smlouva o nájmu</h3>` block through the closing `</div>`) verbatim, parameterised by these variables (no entity coupling — each caller maps its own source):

| Variable | Type | Meaning |
|---|---|---|
| `lesseeName` | string | full name of the nájemce |
| `lesseeIsCompany` | bool | render the company block vs. the person block |
| `lesseeCompanyName`, `lesseeCompanyId`, `lesseeCompanyVatId` | ?string | company identity |
| `lesseeStreet`, `lesseePostalCode`, `lesseeCity` | ?string | billing address |
| `lesseeEmail` | string | e-mail |
| `lesseeBirthDate` | ?\DateTimeImmutable | person DoB (person block only) |
| `storageType` | StorageType | name + `innerWidth`/`innerHeight`/`innerLength` |
| `storageNumber` | string | unit number |
| `startDate` | \DateTimeImmutable | nájem od |
| `endDate` | ?\DateTimeImmutable | nájem do (null = doba neurčitá branch already in the text) |

Then **refactor `order_accept.html.twig`** to `{% include 'public/_contract_terms.html.twig' with { ... } only %}` at the same spot, mapping from `formData`:

```twig
{% include 'public/_contract_terms.html.twig' with {
    lesseeName: customerFullName,
    lesseeIsCompany: (formData.invoiceToCompany and formData.companyName),
    lesseeCompanyName: formData.companyName,
    lesseeCompanyId: formData.companyId,
    lesseeCompanyVatId: formData.companyVatId,
    lesseeStreet: formData.billingStreet,
    lesseePostalCode: formData.billingPostalCode,
    lesseeCity: formData.billingCity,
    lesseeEmail: formData.email,
    lesseeBirthDate: formData.birthDate,
    storageType: storageType,
    storageNumber: storage.number,
    startDate: formData.startDate,
    endDate: formData.endDate,
} only %}
```

The rendered HTML for the order flow must be byte-for-byte the same as today (existing `order_accept` render test must still pass).

### 2. New token-gated route to serve the uploaded contract (scenario-A preview)

New single-action controller `src/Controller/Public/CustomerSigningContractController.php`:

```php
#[Route('/podpis/{token}/smlouva', name: 'public_customer_signing_contract', requirements: ['token' => '[a-f0-9]{64}'])]
```

- Resolve `order = orderRepository->findBySigningToken($token)`. If null, expired (`$order->isExpired($now)`), or `!$order->hasUploadedContract()` → `NotFoundHttpException`.
- The signing token **is** the authorization (same model as the signing page). No login required.
- Serve `Order.uploadedContractDocumentPath` inline via `BinaryFileResponse` with `HeaderUtils::DISPOSITION_INLINE`. Resolve `realpath` and assert it starts with `realpath(contractsDir).'/'` before serving (mirror `OrderContractDownloadController.php:59-63`); 404 on failure. Set `Content-Type` from the extension (`pdf` → `application/pdf`, `jpg`/`jpeg` → `image/jpeg`, `png` → `image/png`).
- Inject `OrderRepository`, `ClockInterface`, and `#[Autowire('%kernel.project_dir%/var/contracts')] string $contractsDirectory` (or reuse `%kernel.project_dir%` like `OrderContractDownloadController`).

### 3. Small `Order` helper for the preview branch

Add to `src/Entity/Order.php`:

```php
public function uploadedContractIsImage(): bool
{
    if (null === $this->uploadedContractDocumentPath) {
        return false;
    }
    $ext = strtolower(pathinfo($this->uploadedContractDocumentPath, PATHINFO_EXTENSION));

    return in_array($ext, ['jpg', 'jpeg', 'png'], true);
}
```

Used by the template to choose `<img>` vs `<object>` for the embed.

### 4. `CustomerSigningController` — compute view flags + validate the new consents

Re-inject `PriceCalculator`. Extract the three render sites (GET `:53`, POST validation-error `:108`, POST exception `:139`) into one private `renderForm(Order $order, array $submitted, \DateTimeImmutable $now): Response` that computes and passes all view variables (mirrors `OrderAcceptController`'s render shape). Compute:

```php
$place = $order->storage->getPlace();
$situation = CustomerBillingSituation::fromOrder($order);
$isPayFlow = CustomerBillingSituation::GOPAY_FIRST_CHARGE === $situation;
$isRecurring = $order->isRecurring();

$showRecurringConsent = $isPayFlow && $isRecurring && BillingMode::AUTO_RECURRING === $order->billingMode;
$showManualInfo       = $isPayFlow && $isRecurring && BillingMode::MANUAL_RECURRING === $order->billingMode;
$showPaymentLogos     = $isPayFlow && PaymentMethod::GOPAY === $order->paymentMethod;
$requiresEarlyStartWaiver = !$order->hasUploadedContract()
    && $order->startDate < $now->setTime(0, 0, 0)->modify('+14 days');

$paymentSchedule = $isRecurring ? $this->priceCalculator->buildScheduleFromOrder($order) : null;
```

Template context (in addition to today's `order`/`storage`/`storageType`/`place`/`priceViewModel`):

```php
'paymentSchedule' => $paymentSchedule,
'isRecurring' => $isRecurring,
'showRecurringConsent' => $showRecurringConsent,
'showManualInfo' => $showManualInfo,
'showPaymentLogos' => $showPaymentLogos,
'requiresEarlyStartWaiver' => $requiresEarlyStartWaiver,
'requiresOperatingRules' => null !== $place->operatingRulesPath,
'recurringPaymentLegalMaxInCzk' => intdiv(PriceCalculator::MAX_RECURRING_PAYMENT_AMOUNT_IN_HALER, 100),
'submitted' => $submitted,
'now' => $now,
```

In `handlePost()`, read the new posted fields and **add** these validations to the existing `$errors` list (keep all current ones; the `accept_contract` rule stays conditional on `hasUploadedContract()`):

```php
$acceptConsumerNotice   = $request->request->getBoolean('accept_consumer_notice');
$acceptOperatingRules   = $request->request->getBoolean('accept_operating_rules');
$acceptRecurringPayments= $request->request->getBoolean('accept_recurring_payments');
$acceptEarlyStartWaiver = $request->request->getBoolean('accept_early_start_waiver');

if (!$acceptConsumerNotice) {
    $errors[] = 'Pro pokračování je nutné souhlasit s poučením o právech spotřebitele.';
}
if (null !== $place->operatingRulesPath && !$acceptOperatingRules) {
    $errors[] = 'Pro pokračování je nutné souhlasit s provozním řádem.';
}
if ($showRecurringConsent && !$acceptRecurringPayments) {
    $errors[] = 'Pro pokračování je nutné souhlasit s podmínkami opakovaných plateb.';
}
if ($requiresEarlyStartWaiver && !$acceptEarlyStartWaiver) {
    $errors[] = 'Pro pokračování je nutné souhlasit se vzdáním se práva na odstoupení od smlouvy ve 14denní lhůtě.';
}
```

On validation error, re-render via `renderForm()` passing the preserved `$submitted` (mirror `OrderAcceptController.php:319-325`): `signingPlace`, `acceptAll` (= all master consents ticked), `acceptRecurring`. The submit path (dispatch `CustomerSignOnboardingCommand`) is unchanged — those consents are page-gating, not stored on the command (the recurring consent record is captured by GoPay at payment time, as in the order flow; existing behaviour).

### 5. Rewrite `customer_signing.html.twig` to mirror `order_accept.html.twig`

Keep the existing Stimulus/Alpine scaffolding (`signature form-guard`, signature radios, hidden `signature_*` fields, `form-guard` summary + locking submit). Apply:

**(a) Situation-aware title / heading / intro / button** — branch on `order.hasUploadedContract()`:

| | Scenario B (no upload) | Scenario A (uploaded) |
|---|---|---|
| `{% block title %}` | `Podpis smlouvy - Fajnesklady.cz` | `Přijetí obchodních podmínek - Fajnesklady.cz` |
| `<h1>` | `Podpis smlouvy` | `Přijetí a podpis obchodních podmínek` |
| intro `<p>` | `Zkontrolujte prosím smlouvu a podmínky a podepište je elektronicky.` | `Vaši smlouvu jsme připravili a najdete ji níže. Zkontrolujte ji, odsouhlaste obchodní podmínky a potvrďte podpisem.` |
| submit `<span data-form-guard-target="label">` | `Podepsat smlouvu` | `Přijmout a podepsat` |

Keep the existing prepaid/free green situation banner (`:30-40`) above the heading unchanged. Labels stay signing-focused — **do not** use `OBJEDNÁVÁM a zaplatím` and **do not** add the § 1826a binding disclosure (the signing button never charges; the binding payment commitment + that disclosure live on `order_payment.html.twig`).

**(b) Contract block** — replace the order-summary-only body with:

```twig
{% if order.hasUploadedContract() %}
    {# Scenario A — preview the admin-uploaded contract. Token-gated route. #}
    <div class="border border-gray-200 rounded-lg p-4 mb-6 bg-white">
        <h3 class="font-semibold text-gray-900 mb-3">Vaše smlouva</h3>
        <p class="text-sm text-gray-600 mb-3">Smlouvu nahrál pronajímatel. Níže si ji prosím zkontrolujte.</p>
        {% set contractUrl = path('public_customer_signing_contract', { token: order.signingToken }) %}
        {% if order.uploadedContractIsImage() %}
            <img src="{{ contractUrl }}" alt="Nahraná smlouva" class="w-full rounded-lg border border-gray-200">
        {% else %}
            <object data="{{ contractUrl }}#toolbar=0" type="application/pdf" class="w-full rounded-lg border border-gray-200" style="height: 70vh; min-height: 480px;">
                <p class="text-sm text-gray-600">Náhled nelze zobrazit. <a href="{{ contractUrl }}" target="_blank" rel="noopener" class="text-accent hover:underline">Otevřít smlouvu v novém okně</a>.</p>
            </object>
        {% endif %}
        <p class="mt-2 text-xs text-gray-500"><a href="{{ contractUrl }}" target="_blank" rel="noopener" class="text-accent hover:underline">Otevřít smlouvu v novém okně</a></p>
    </div>
{% else %}
    {# Scenario B — the exact contract text the order flow shows. #}
    {% include 'public/_contract_terms.html.twig' with {
        lesseeName: customerFullName,
        lesseeIsCompany: (order.user.companyName is not empty),
        lesseeCompanyName: order.user.companyName,
        lesseeCompanyId: order.user.companyId,
        lesseeCompanyVatId: order.user.companyVatId,
        lesseeStreet: order.user.billingStreet,
        lesseePostalCode: order.user.billingPostalCode,
        lesseeCity: order.user.billingCity,
        lesseeEmail: order.user.email,
        lesseeBirthDate: order.user.birthDate,
        storageType: storageType,
        storageNumber: storage.number,
        startDate: order.startDate,
        endDate: order.endDate,
    } only %}
{% endif %}
```

Keep the order-summary card + photos (existing `:55-121`) and the "Údaje nájemce" card. Add the lock disclaimer (`{% include 'components/lock_disclaimer.html.twig' %}`) before the contract block, matching `order_accept.html.twig:139-141`.

**(c) Consolidated consent bundle** — extend the existing block (`:230-261`) to match `order_accept.html.twig:356-397`. The Alpine root adds `acceptRecurring` (default per the recurring gate, mirror `order_accept.html.twig:48`). Bullets and hidden inputs:

- Contract bullet: scenario B → `smluvními podmínkami uvedenými výše v této smlouvě`; scenario A → `smlouvou nahranou pronajímatelem (zobrazenou výše)`. (`accept_contract` hidden stays `1`, auto-set in scenario A as today; in scenario B `:disabled="!acceptAll"`.)
- VOP bullet (modal `vop`) — keep.
- **NEW** operating-rules bullet `{% if requiresOperatingRules %}` → link `upload_url(place.operatingRulesPath)` (mirror `order_accept.html.twig:373-375`), with hidden `accept_operating_rules` `value="1" :disabled="!acceptAll"`.
- **NEW** consumer-notice bullet (modal `consumer`), hidden `accept_consumer_notice` `value="1" :disabled="!acceptAll"`.
- GDPR bullet (modal `gdpr`) — keep.
- e-signature bullet — keep.
- **NEW** early-start waiver bullet `{% if requiresEarlyStartWaiver %}` (mirror `order_accept.html.twig:380-382`), hidden `accept_early_start_waiver` `value="1" :disabled="!acceptAll"`.
- Recurring consent is **NOT** in this master list — it lives in its own dedicated checkbox (next item), per the GoPay rule.

**(d) MANUAL info card + AUTO recurring params card** — port `order_accept.html.twig:405-520` verbatim, gated on the new flags instead of `formData.billingMode`:

```twig
{% if showManualInfo %}  {# replaces `formData.billingMode.value == 'manual_recurring'` #}
    … "Ručně schvalovaná platba" info card …
{% endif %}

{% if showRecurringConsent %}  {# replaces `isRecurring and formData.billingMode.value == 'auto_recurring'` #}
    <div id="sign-recurring" class="bg-amber-50 …">
        … "Parametry opakované platby" rows …
        … dedicated "Souhlasím s opakovanou platbou" checkbox (x-model="acceptRecurring",
          data-form-guard-target="required", data-form-guard-anchor="#sign-recurring") …
        <input type="hidden" name="accept_recurring_payments" value="1" :disabled="!acceptRecurring">
        … PCI-DSS Level 1 disclosure …
    </div>
{% endif %}
```

The params card MUST read its numbers from `paymentSchedule` (`buildScheduleFromOrder`, so admin price overrides are honoured): `paymentSchedule.monthlyAmountInCzk`, `paymentSchedule.isOpenEnded`, `paymentSchedule.entryCount`, `order.endDate`, and `recurringPaymentLegalMaxInCzk`. Keep the exact GoPay-filed row labels/order from `.claude/COMPLIANCE.md` — do not reword.

**(e) Contextual payment logos** — port `order_accept.html.twig:522-533` gated on the new flag:

```twig
{% if showPaymentLogos %}
    <div class="flex flex-col items-center gap-2 py-2">
        {% include 'components/payment_logos.html.twig' %}
        <p class="text-xs text-gray-500 …">Vaše platba je zabezpečena 256-bit SSL/TLS šifrováním a 3D Secure 2.0.</p>
    </div>
{% endif %}
```

**(f) Modals** — the page currently embeds `vop` + `gdpr`. Add `consumer` (always) and `recurring` (`{% if showRecurringConsent %}`), mirroring `order_accept.html.twig:576-586`:

```twig
{% embed 'public/_term_modal.html.twig' with { id: 'consumer', title: 'Poučení o právech Spotřebitele' } %}
    {% block body %}{% include 'public/_consumer_notice_content.html.twig' %}{% endblock %}
{% endembed %}
{% if showRecurringConsent %}
    {% embed 'public/_term_modal.html.twig' with { id: 'recurring', title: 'Podmínky opakovaných plateb' } %}
        {% block body %}{% include 'public/_recurring_payments_terms_content.html.twig' %}{% endblock %}
    {% endembed %}
{% endif %}
```

### 6. Tests

**Integration — `tests/Integration/Controller/CustomerSigningControllerTest.php`** (extend; use `OnboardingFixtures` golden orders, add fixtures if a needed billing shape is missing):

- Scenario B (no uploaded contract): GET shows `Podpis smlouvy`, the contract text (`I. Předmět smlouvy` + the lessee's name), and **no** preview `<object>`.
- Scenario A (uploaded contract): GET shows `Přijetí a podpis obchodních podmínek`, a preview pointing at `public_customer_signing_contract`, and **no** `I. Předmět smlouvy` contract text.
- GoPay AUTO_RECURRING onboarding: page contains `Parametry opakované platby`, the dedicated recurring checkbox (`accept_recurring_payments`), the PCI-DSS text, and the payment logos (`aria-label="Visa"`).
- BANK_TRANSFER recurring onboarding: **no** payment logos; MANUAL info card (`Ručně schvalovaná platba`) present.
- Externally-prepaid / free onboarding: green banner present; **no** payment logos, **no** recurring consent.
- POST missing `accept_consumer_notice` → re-renders with the consumer-notice error (order not signed). POST missing `accept_recurring_payments` on an AUTO GoPay recurring order → re-renders with the recurring error. POST missing `accept_operating_rules` when the place has rules → error.
- Early-start waiver: scenario B order with `startDate` within 14 days → waiver bullet present + required; scenario A order → waiver bullet absent.

**Integration — `tests/Integration/Controller/CustomerSigningContractControllerTest.php`** (new): valid token + uploaded contract (real fixture PDF) → 200, `Content-Type: application/pdf`, inline disposition, bytes equal the file. Order without uploaded contract → 404. Unknown token → 404.

**Integration — `tests/Integration/Controller/OrderAcceptControllerTest.php`** (existing): must still pass after the contract-partial extraction (regression guard that the order flow's contract HTML is unchanged).

**Unit — `tests/Unit/Entity/OrderTest.php`** (extend): `uploadedContractIsImage()` true for `.png`/`.jpg`, false for `.pdf` and null path.

### 7. Docs

- **`PROJECT_MAP.md`** — add route `/podpis/{token}/smlouva` → `Public\CustomerSigningContractController` under "Public — Place browsing & ordering"; note the new shared partial `templates/public/_contract_terms.html.twig`.
- **`.claude/COMPLIANCE.md`** — under "Payment-method logos & security indicators" and "Recurring payments", add `templates/public/customer_signing.html.twig` to the list of surfaces that render the contextual logos + dedicated recurring consent (so the next compliance audit knows the onboarding signing page is now in scope). Note the rule: logos/recurring-consent appear only for the GoPay first-charge pay flow.
- **`BACKLOG.md`** — append the row for spec 072.

## Acceptance

- [ ] `docker compose exec web composer quality` is green (cs:fix, phpstan level 8, unit + integration).
- [ ] `docker compose exec web composer test` is green (full 1100+ suite — this touches controller + templates + form behaviour).
- [ ] **Scenario B**: a fresh (no-upload) onboarding signing page is titled "Podpis smlouvy" and renders the full "Smlouva o nájmu" text (Pronajímatel / Nájemce / I–VI) identical to `/prijmout`, populated from the order's customer + storage + dates. **Verified in browser.**
- [ ] **Scenario A**: an uploaded-contract onboarding signing page is titled "Přijetí a podpis obchodních podmínek", previews the uploaded PDF/image inline (and "Otevřít v novém okně" works), shows no inline contract text, and the submit button reads "Přijmout a podepsat". The preview URL is token-gated (works logged-out; 404 without the token / without an uploaded contract).
- [ ] **Logos contextual**: GoPay onboarding shows the card/3DS/GoPay logos + SSL note; bank-transfer, externally-prepaid, and free onboardings show **no** logos.
- [ ] **Recurring consent**: an AUTO_RECURRING GoPay onboarding shows the "Parametry opakované platby" card (amounts from the order's locked-in price, honouring an admin override), the dedicated "Souhlasím s opakovanou platbou" checkbox, and the PCI-DSS disclosure; submitting without ticking it is rejected. A MANUAL_RECURRING (incl. bank transfer / yearly) onboarding shows the "Ručně schvalovaná platba" info card and **no** dedicated recurring checkbox. Short LIMITED (< 28 d, one-shot) shows neither.
- [ ] **Consent parity**: consumer-notice consent is required on every onboarding signing; operating-rules consent is required when the place has operating rules; the 14-day withdrawal waiver appears + is required only for scenario B with start within 14 days.
- [ ] The order flow (`/prijmout`) is visually + behaviourally unchanged after the contract-partial extraction.
- [ ] `PROJECT_MAP.md`, `.claude/COMPLIANCE.md`, `BACKLOG.md` updated.

## Out of scope

- **Admin onboarding form (`AdminOnboardingForm` + `AdminOnboardingFormData`).** It already collects payment method, billing mode, pricing, uploaded contract. No changes — this spec is purely the customer-facing signing page + its preview route.
- **The payment / debt / status pages.** `order_payment.html.twig`, `order_debt_payment.html.twig`, `order_status.html.twig` already gate logos to the card branch and are correct; not touched.
- **The completion page** (`customer_signing_complete.html.twig`) — already situation-aware (spec 043); not touched.
- **The signing-link e-mail.** `SigningEmailContent` (spec 043) still says "Podpis smlouvy" for the uploaded-contract case. Retitling the e-mail to match scenario A is a reasonable follow-up but would mean threading `hasUploadedContract` into that VO — deferred; the user flagged the page, not the e-mail.
- **Persisting the consumer-notice / operating-rules / waiver consents as structured columns.** The order flow doesn't store them as discrete flags either — they are page-gating consents recorded implicitly by the signature + the consent record GoPay keeps. Don't add new columns.
- **§ 1826a binding button / "OBJEDNÁVÁM a zaplatím" on the signing page.** Decided against: the signing button never triggers a charge (GoPay payment is the next page, which already carries the binding label + disclosure). Keep signing-focused labels.
- **Re-deriving the recurring schedule math.** Reuse `PriceCalculator::buildScheduleFromOrder()` as-is.

## Open questions

None — proceed.
</content>
</invoke>
