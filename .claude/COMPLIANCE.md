# Compliance ruleset (GoPay + VOP + Czech consumer law)

These rules are **load-bearing** for the order flow. They are derived from binding sources — Czech consumer protection law, GoPay's published merchant requirements, and our own legal documents (VOP, Podmínky opakovaných plateb, Poučení spotřebitele). **Do not change them without consulting the lawyer or re-reading the source.** When sources update, update this file in the same commit.

> Audit trail and the *why* for each rule live in [`specs/016-gopay-vop-compliance.md`](specs/016-gopay-vop-compliance.md). When this file says "must" or "MUST", treat it as non-negotiable in production.

## Sources of truth

| Source | What it governs |
|---|---|
| **OZ § 1826a odst. 2** ("tlačítková novela", zákon č. 374/2022 Sb.) | Order-button label + binding-payment disclosure |
| **OZ § 1820 / § 1829 / § 1837** | Pre-contract info, 14-day withdrawal, exclusion when service started before 14 days |
| **Zákon č. 634/1992 Sb. o ochraně spotřebitele** | General consumer protection, ČOI dispute resolution |
| **GoPay — Podmínky používání platební brány (1.4.2025)** | Merchant obligations on the merchant's website |
| **GoPay — Náležitosti prodejního místa** | Concrete checklist of what the website must contain |
| **GoPay — Jaké informace musím prezentovat pro opakované platby** | Recurring-payment disclosure rules |
| **Naše VOP** (`public/documents/vop.pdf`) | Our customer-facing terms; binds button label, contact info, structure |
| **Naše Podmínky opakovaných plateb** (`public/documents/podminky-opakovanych-plateb.pdf`) | Recurring-payment specifics |
| **Naše Poučení spotřebitele** (`public/documents/pouceni-spotrebitele.pdf`) | Withdrawal, complaints, dispute resolution wording |

## Order button & pre-contract disclosure

- **Submit button label MUST read exactly `OBJEDNÁVÁM a zaplatím`** (capitalisation as written, with diacritics). This is the formulation chosen in VOP III.2 and satisfies OZ § 1826a odst. 2's "Objednávka zavazující k platbě nebo jiná odpovídající jednoznačná formulace". Any deviation risks the contract being declared invalid and a ČOI fine up to 5 M Kč.
- **Immediately above the submit button**, a small-print disclosure MUST appear: *"Kliknutím na tlačítko **OBJEDNÁVÁM a zaplatím** odesíláte závaznou objednávku, která zavazuje k zaplacení sjednané ceny."*
- This wording must appear consistently in any e-mail, screenshot, help text, or marketing copy that quotes the button.

## Identification block (every order-flow page)

The following **MUST** be visible on the place detail page, the order form, the order accept page, the payment page, the success page, the footer, and inside the contract text:

- Obchodní firma: **Mekmann s.r.o.**
- IČO: **11678631**
- DIČ: **CZ11678631**
- Sídlo: **Dvořákova 780, 739 11 Frýdlant nad Ostravicí**
- Zápis: **Krajský soud v Ostravě, oddíl C, vložka 86521**

## Canonical contact details

These are the **website-canonical** values. The PDFs may say something else — that's a documented [lawyer-fix](specs/016-lawyer-handoff.md) item, **do not** change the website to match the PDFs.

| Purpose | Channel | Value |
|---|---|---|
| General contact / contracts / withdrawals | telefon | **+420 605 522 566** |
| General contact / contracts / withdrawals | e-mail | **skladmistr@fajnesklady.cz** |
| Recurring-payment changes / cancellation | e-mail | **simek@fajnesklady.cz** (per Podmínky VI; this is intentional — kept distinct so recurring-payment ops route to the right person) |
| Hlavní pobočka (provoz) | adresa | Collo Louky 1557, 738 01 Frýdek-Místek |

## Billing modes (the three shapes of a rental)

Every rental falls into exactly one of three modes. The customer-facing quote, the order entity's `totalPrice`, the GoPay call shape, and the recurring-billing cron all branch on the same rule. **Don't add a fourth mode without re-deriving the whole flow.**

| Mode | When it applies | First charge | Subsequent charges | GoPay call shape |
|---|---|---|---|---|
| **One-shot (krátkodobý)** | Limited rental, < 28 dní | Full prorated total (weekly rate × weeks + daily tail) | None | `createPayment` (no `recurrence`) |
| **Recurring fixed-end** | Limited rental, ≥ 28 dní (cap: 1 rok / 12 měsíců) | Full month at start | Cron runs monthly via `createRecurrence`. **Last charge is prorated** (`remainingDays × monthlyRate / 30`) | First charge: `createPayment` with `recurrence_cycle = ON_DEMAND`. Subsequent: `createRecurrence($parentPaymentId, …)` |
| **Recurring open-ended (doba neurčitá)** | Unlimited rental | Full month at start | Same monthly rate forever via `createRecurrence`, until customer cancels | Same as fixed-end, but with no end date in the schedule |

Hard rules:

- **Limited rentals are capped at 1 rok.** The customer-facing form (`OrderFormData::validateDates`) refuses anything longer and points the customer at "doba neurčitá". This keeps the prorated-tail math from running across an arbitrarily long horizon and keeps recurring contracts comfortably under the GoPay 15 000 Kč single-charge ceiling.
- **The "is this recurring?" question has exactly one source of truth: `PriceCalculator::needsRecurringBilling()`.** It returns `true` for unlimited *and* fixed-end ≥ 28 dní. Anywhere we render the recurring-payment consent block, gate on this — **not** on `endDate === null` (that historically hid the consent for fixed-end orders that nonetheless set up an ON_DEMAND token = compliance breach).
- **The exact amount and date of every charge has exactly one source of truth: `PriceCalculator::buildPaymentSchedule()`.** It returns a `PaymentSchedule` value object with the full `[(date, amount), …]` list (open-ended schedules show only the first entry; the rest are added by the cron after each successful billing cycle). The customer-facing surfaces (order_create / order_accept / order_payment) and the recurring cron (`ChargeRecurringPaymentHandler::calculateBillingAmount`) MUST produce identical numbers — keep them in sync. There are unit tests pinning the math; if you need to change the cadence (e.g., switch away from `\DateTimeImmutable::modify('+1 month')` calendar months) update both call sites and the tests in the same commit.
- **Cancellation does NOT retroactively refund.** Per Podmínky opakovaných plateb čl. VI, cancelling the recurring stops future charges; already-rendered service is settled. The "settle outstanding usage on cancel" feature for open-ended contracts is tracked separately (see backlog spec 019 if open).

## Recurring payments (opakované platby)

Hard requirements drawn from GoPay rules and Podmínky opakovaných plateb:

- **Consent MUST be obtained via a dedicated, visibly separate checkbox** — not folded into a bundled "I agree to everything" master checkbox. The parameters of the recurring payment must be visually adjacent to that checkbox. Buried-in-T&C consent is explicitly disallowed by GoPay.
- **GoPay recurrence type used by this site: `ON_DEMAND` for ALL recurring contracts** (fixed-end ≥ 28 dní *and* doba neurčitá). `recurrence_date_to = 2099-12-31` (effectively "no expiry"; per GoPay docs, must be strictly less than 2099-12-31, and our SDK accepts the boundary value). We never use `recurrence_cycle = DAY / WEEK / MONTH` (Automatic) — we want gate-per-charge on contract state, prorated tail support, and unified retry logic. If you ever consider switching unlimited rentals to Automatic, re-read [`specs/016-gopay-vop-compliance.md`](specs/016-gopay-vop-compliance.md) and the GoPay decision log first.
- Parameters that MUST be displayed at the consent point — **in this order, with these exact labels**, because the order/labels are the ones we filed with GoPay underwriting:
  - **Účel platby** — "Pronájem skladovací jednotky"
  - **Maximální částka opakované platby** — **15 000 Kč** (legal ceiling per Podmínky III)
  - **Částka jednotlivé platby** — explicit **Fixní** label + the actual monthly amount in CZK incl. VAT. For fixed-end ≥ 28 dní rentals, must also disclose that the last installment is prorated by remaining days (otherwise GoPay treats the disclosure as misleading).
  - **Frekvence a den strhávání** — explicit **Fixní** label + "měsíční, vždy ke stejnému dni v měsíci jako první platba; pokud připadne na nepracovní den, strhne se následující pracovní den" (kept consistent with Podmínky opakovaných plateb čl. III).
  - **Doba trvání** — "po celou dobu trvání nájmu / do odvolání souhlasu" (unlimited) **or** "{N} platby do {date}" (fixed-end).
  - **Forma komunikace pro změnu nastavení** — e-mailem na `simek@fajnesklady.cz` (this is a SEPARATE row from cancellation per GoPay checklist — even if the channel is the same, both rows must be present).
  - **Zrušení a storno opakované platby** — e-mailem na `simek@fajnesklady.cz`, kdykoli, bez sankce; odkaz na Podmínky čl. VI.
- A **PCI-DSS Level 1** disclosure MUST appear immediately below the dedicated consent checkbox — not only inside the linked Podmínky modal. Required text:
  > Společnost GOPAY s.r.o. nakládá s údaji platební karty podle mezinárodního bezpečnostního standardu PCI-DSS Level 1 — nejvyšší úroveň datové bezpečnosti ve finančním sektoru. Čísla karet ani CVV s námi GoPay nesdílí a Pronajímatel k nim nemá přístup.
- **Customer notifications:**
  - Within **2 working days** of consent: confirmation that the recurring payment was established (Podmínky IV).
  - At least **7 working days** before any charge if more than 6 months elapsed since the last successful charge (Podmínky V).
  - At least **7 working days** before any change to recurring-payment parameters (frequency, amount).
- Customer's consent record (timestamp, IP, params) MUST be retained for **at least 12 months past termination** of the recurring agreement.
- Customer MUST be able to cancel the recurring payment at any time. Cancellation does not retroactively affect already-rendered services.

### Yearly cadence (spec 045)

When the customer (or admin during onboarding) picks **Roční platba**, the contract is billed as a one-shot annual GoPay payment, NOT as an `ON_DEMAND` recurring charge. The legal consequences:

- The **15 000 Kč single-charge ceiling** from Podmínky opakovaných plateb čl. III does NOT apply — a yearly payment is a normal one-time charge, not a "single recurring charge". `MAX_RECURRING_PAYMENT_AMOUNT_IN_HALER` and the Podmínky PDF text remain accurate as written.
- The **dedicated recurring-payment consent checkbox** described above MUST NOT be shown for YEARLY orders. The order accept template gates the consent block on `formData.billingMode.value == 'auto_recurring'`; YEARLY forces `MANUAL_RECURRING`, so the block is hidden by construction. Don't add a parallel block for yearly — there is no token, no silent debit, and no Podmínky-čl.-III parameter disclosure obligation.
- **No Podmínky PDF re-issuance** is required when yearly is enabled per place — the document covers ON_DEMAND recurring charges, which yearly does not use.
- Customer is reminded by e-mail 7 days before each yearly cycle (per `ManualBillingReminderSchedule`) and pays via a fresh one-shot GoPay link. This is the same MANUAL track spec 036 introduced; the only difference is the cadence anchor (`+1 year` instead of `+1 month`).
- The "platba předem na celý rok" VOP wording is implicit in the per-order schedule; no new disclosure obligation under § 1826a OZ (no auto-renewal on a stored token).

## Payment-method logos & security indicators

The following MUST appear on the order form, order accept page, payment page, and footer (any page where the sale or payment happens):

- **Visa** (full colour)
- **Mastercard** (full colour)
- **Maestro** (full colour) — only if accepted via GoPay
- **Visa Secure** (formerly Verified by Visa) — 3D Secure indicator
- **Mastercard ID Check** (formerly SecureCode) — 3D Secure indicator
- **GoPay** logo, wrapped in `<a href="https://www.gopay.com" target="_blank" rel="noopener">…</a>`
- Text indicator: **"Zabezpečeno SSL/TLS šifrováním a 3D Secure 2.0"** near the payment area on `order_accept.html.twig` and `order_payment.html.twig`

If we ever enable Apple Pay / Google Pay through GoPay, their official wordmarks are added to the same partial.

## Pricing & VAT

- Every customer-facing price is shown **with VAT** with the suffix `vč. DPH` next to the amount.
- Currency is always rendered as `Kč` (not `CZK`).
- Use the `price_with_vat` Twig macro in `templates/macros/price.html.twig`. Don't repeat the format inline; if the macro doesn't fit, extend it.

## Consumer rights & links

- The following links MUST be reachable from any page describing the sale (footer is enough):
  - Všeobecné obchodní podmínky (VOP)
  - Poučení o právech spotřebitele
  - Podmínky opakovaných plateb
  - Ochrana osobních údajů (GDPR)
  - **Mimosoudní řešení sporů — Česká obchodní inspekce: <https://www.coi.cz>**
- The order form (pre-purchase) MUST also link to VOP, Poučení spotřebitele, and Podmínky opakovaných plateb (a terse inline list above the submit button is enough).
- Withdrawal & complaint forms MUST be downloadable as PDF (`public/documents/formular-odstoupeni-od-smlouvy.pdf`, `public/documents/reklamacni-formular.pdf`). The printable forms inside `pouceni-spotrebitele.pdf` are kept as a fallback.

## Cookie / tracking note (intentionally out of scope here)

Cookie-consent compliance is governed separately. Don't add it to this file unless we're updating that flow.

## Where this is enforced in code

- `src/Service/PriceCalculator.php` — `buildPaymentSchedule()` (single source of truth for the customer-facing schedule and the cron amounts), `needsRecurringBilling()` (single source of truth for "should we set up an ON_DEMAND token").
- `src/Value/PaymentSchedule.php` + `src/Value/PaymentScheduleEntry.php` — value objects passed to every billing-related template.
- `src/Form/OrderFormData.php` `validateDates()` — enforces the 1-rok cap on limited rentals.
- `src/Command/ChargeRecurringPaymentHandler.php` `calculateBillingAmount()` — the cron equivalent of `buildPaymentSchedule`'s tail-prorate branch; **must stay in sync**.
- `templates/components/OrderForm.html.twig`, `templates/public/order_accept.html.twig`, `templates/public/order_payment.html.twig` — render the schedule.
- `templates/public/order_accept.html.twig` — submit button label, pre-disclosure, dedicated recurring consent, parameters card.
- `templates/public/order_payment.html.twig` — identification block, SSL/3DS notice, GoPay logos.
- `templates/components/OrderForm.html.twig` — pre-purchase legal-doc links, VAT in prices.
- `templates/components/payment_logos.html.twig` — card + 3DS + GoPay-link logos.
- `templates/components/footer.html.twig` — full identification, ČOI link, all PDF/legal links, SSL notice.
- `templates/macros/price.html.twig` — VAT macro.
- `src/Event/Order/RecurringPaymentEstablished.php` + handler — 2-business-day confirmation e-mail.
- `src/Console/NotifyUpcomingRecurringChargeCommand.php` — daily 7-business-day pre-charge notice.

## Don't quietly drift

If you're touching one of these files and the change conflicts with a rule above, **stop and re-read the source**. The most common drift mode is "minor copy tweak" that loosens the button label or buries the recurring consent again — both have historically been the worst offenders. Either keep the rule, or open an issue and consult the lawyer first.
