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

## Recurring payments (opakované platby)

Hard requirements drawn from GoPay rules and Podmínky opakovaných plateb:

- **Consent MUST be obtained via a dedicated, visibly separate checkbox** — not folded into a bundled "I agree to everything" master checkbox. The parameters of the recurring payment must be visually adjacent to that checkbox. Buried-in-T&C consent is explicitly disallowed by GoPay.
- Parameters that MUST be displayed at the consent point:
  - Účel platby (e.g. "Pronájem skladového kontejneru")
  - Pevná částka pro tuto smlouvu (in CZK)
  - **Maximální částka opakované platby: 15 000 Kč** (legal ceiling per Podmínky III)
  - Frekvence: Měsíční, vždy ke stejnému dni v měsíci jako první platba
  - Doba trvání: po celou dobu trvání nájmu / do odvolání souhlasu
  - Způsob zrušení: e-mailem na `simek@fajnesklady.cz`
  - Odkaz na plné Podmínky opakovaných plateb
- **Customer notifications:**
  - Within **2 working days** of consent: confirmation that the recurring payment was established (Podmínky IV).
  - At least **7 working days** before any charge if more than 6 months elapsed since the last successful charge (Podmínky V).
  - At least **7 working days** before any change to recurring-payment parameters (frequency, amount).
- Customer's consent record (timestamp, IP, params) MUST be retained for **at least 12 months past termination** of the recurring agreement.
- Customer MUST be able to cancel the recurring payment at any time. Cancellation does not retroactively affect already-rendered services.

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
