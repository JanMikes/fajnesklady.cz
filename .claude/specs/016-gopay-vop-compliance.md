# 016 — GoPay & VOP compliance pass on the ordering flow

**Status:** done (all 3 phases shipped in commit a1aaa6d)
**Type:** compliance / legal
**Scope:** large (10–12 files; legal text + UI labels + emails + cron)
**Depends on:** 015 (consolidated consent — partially superseded for recurring payments). Adds to 010/011/013.

## Why this exists

We're preparing the GoPay integration for production activation. GoPay's underwriting team checks a concrete list of website requirements (logos, contact info, terms, recurring-payment disclosure). Independently, Czech consumer law (občanský zákoník § 1826a, "tlačítková novela") imposes hard rules on checkout buttons and pre-contract disclosure. Our own VOP, Podmínky opakovaných plateb, and Poučení spotřebitele — all referenced from the order accept page — also need to match the live behaviour and have unfilled placeholders.

This spec audits the gap between (a) what the legal docs and GoPay/ČOI rules require and (b) what the current ordering flow does, then prescribes the specific changes. It does **not** rewrite the legal docs themselves (lawyer's job); it fixes our website and order flow to match them, and lists the placeholder/text issues for the lawyer to fill in.

## Authoritative sources used

1. **VOP** — `public/documents/vop.pdf` (Mekmann s.r.o., 12 pages, articles I–XVI + Příloha 1, 2).
2. **Podmínky opakovaných plateb** — `public/documents/podminky-opakovanych-plateb.pdf` (4 pages, articles I–VIII, effective 1.5.2025).
3. **Poučení spotřebitele** — `public/documents/pouceni-spotrebitele.pdf` (3 pages: rights notice + withdrawal form + complaint form).
4. **GoPay — Podmínky používání platební brány, účinnost od 1.4.2025** — official obligations on the merchant's website.
5. **GoPay — Náležitosti prodejního místa** — concrete checklist (company ID, address, contact, prices/VAT, T&C, complaints, withdrawal, GDPR, card logos, 3D Secure logos, GoPay logo with link to www.gopay.com).
6. **GoPay — Jaké informace musím prezentovat pro opakované platby** — recurring-payment disclosure rules (params in a visible place separate from T&C, explicit consent, 2-business-day confirmation, 7-business-day pre-charge notice for ≥6-month gaps and parameter changes, retain consent ≥12 months past termination).
7. **OZ § 1826a odst. 2** ("tlačítková novela") — order-binding-to-payment button must read "Objednávka zavazující k platbě" or "jiná odpovídající jednoznačná formulace"; otherwise the contract is **invalid** and ČOI may fine up to 5M Kč.

## Findings (gap matrix)

Items grouped by risk. **C = compliance-critical** (legal/contract validity), **G = GoPay underwriting blocker**, **U = UX-quality / consistency**.

### C-1 — Submit button label does not match VOP and § 1826a wording

- **VOP III.2** (page 2) literally specifies: *"Objednávku odešle Nájemce kliknutím na tlačítko **„OBJEDNÁVÁM a zaplatím"**."* — highlighted in the source PDF.
- **Current:** `templates/public/order_accept.html.twig:447` renders **"Objednat a zaplatit"**.
- **Risk:** § 1826a odst. 2 OZ requires the button to read "Objednávka zavazující k platbě" *or another equivalent unambiguous formulation*. The VOP picked "OBJEDNÁVÁM a zaplatím" as our chosen formulation — the website must match. ČOI can argue the current "Objednat a zaplatit" is non-compliant (verb form, no caps, drift from VOP). Worst case: contracts concluded via the current button are challengeable as invalid.
- **Fix:** rename the submit button to **"OBJEDNÁVÁM a zaplatím"** in `order_accept.html.twig:447` and any duplicate (e.g. `customer_signing.html.twig` if applicable — review). Keep capitalisation as in the VOP. Update any e-mail copy that quotes the button.

### C-2 — Pre-contract "I am ordering with an obligation to pay" disclosure

- § 1826a odst. 2 OZ also requires the merchant to ensure the consumer **explicitly acknowledges** that clicking the button binds them to payment.
- **Current:** the master consent checkbox (per spec 015) lists "smluvními podmínkami uvedenými výše v této smlouvě" but no sentence specifically about *"objednávkou se zavazuji zaplatit"*.
- **Fix:** immediately above the submit button in `order_accept.html.twig` (between the consolidated checkbox and the sticky footer / submit), add a small-print line:

  > *"Kliknutím na tlačítko **OBJEDNÁVÁM a zaplatím** odesíláte závaznou objednávku, která zavazuje k zaplacení sjednané ceny."*

  This is the standard formulation recommended by ČOI guidance. Plain `<p class="text-xs text-gray-500 mt-2">` is enough.

### C-3 — Recurring-payment consent is buried inside the consolidated single-checkbox (spec 015) — GoPay requires it visible, **separate from T&C**

- **GoPay rule:** *"Parametry opakované platby umístěte na viditelné místo (oddělené od obchodních podmínek), kde zákazník udělí souhlas s jejím založením."*
- **Podmínky opakovaných plateb I:** *"Váš souhlas získáváme … zaškrtnutím **příslušného okénka pro souhlas s opakovanou platbou** přímo v objednávkovém formuláři."* — i.e. a dedicated checkbox.
- **Current:** `templates/public/order_accept.html.twig:396-398` lists "opakované platby" as one bullet inside the single master checkbox; the dedicated `accept_recurring_payments` field is mirrored as a hidden input bound to the master flag (line 413). Visually it reads as one of seven equivalent items.
- **Fix:** when `isRecurring` is true, render a **second, dedicated, visible checkbox** *outside and below* the master consent block, scoped only to recurring payments. Mirror its `accept_recurring_payments` hidden field independently. Include the parameters block right above this dedicated checkbox so they're visually grouped (the existing parameters card at lines 116–165 already exists; move it to sit immediately above the checkbox, not above the customer-info card). Submit gating becomes `:disabled="!(signed && acceptAll && (!isRecurring || acceptRecurring) && signingPlace.trim())"`.
- **Server:** `OrderAcceptController.php:141` already validates `accept_recurring_payments` separately. No PHP change.

### C-4 — Confirm establishment of the recurring payment within 2 business days

- **Podmínky opakovaných plateb IV:** *"Poté, co Nájemce odsouhlasí vznik opakované platby, Pronajímatel mu do 2 pracovních dní … že platba byla skutečně založena."*
- **GoPay rule:** same.
- **Current:** there's a payment-success email (`OrderCompleteController` flow), but no event-based "your recurring payment is now established" confirmation that fires off the GoPay webhook for the *initial* recurring transaction.
- **Fix:** on receipt of GoPay's PAID notification for an initial `ON_DEMAND` recurring payment, emit a domain event (`RecurringPaymentEstablished`) and an email handler that sends a confirmation containing:
  1. Maximum amount (15 000 Kč, per Podmínky opakovaných plateb III).
  2. Frequency (monthly, on the same day of month as the first payment).
  3. Period (until consent withdrawn — VOP IV.1.a).
  4. Cancellation contact (email + phone — see C-9 for which to use).
  5. Link to the Podmínky opakovaných plateb PDF.

  Implement under `src/Event/Order/RecurringPaymentEstablished.php` + handler `src/Event/Order/SendRecurringPaymentConfirmationHandler.php`. The GoPay status processor (`src/Command/ProcessRecurringPaymentCommand.php` already exists for subsequent charges — extend or reuse) is the dispatch point.

### C-5 — 7-business-day pre-charge notice when ≥6 months since last recurring payment

- **Podmínky opakovaných plateb V:** *"Pronajímatel informuje Nájemce alespoň 7 pracovních dní předem, když: bude stržena platba … a uběhlo více než 6 měsíců od poslední opakované platby."* (Plus when any parameter changes — same lead time.)
- **Current:** no such pre-charge notice exists.
- **Fix:** scheduled command `app:notify-upcoming-recurring-charge` (or extend `ProcessRecurringPaymentCommand`) that, daily, finds active recurring orders whose **next charge date is in 7 business days** AND whose **last successful charge was >6 months ago**, and sends them an email with: amount, expected charge date, how to cancel. Configure cron (`config/packages/messenger.php` or `crontab` on host).
- **Out of scope for this spec:** the fully general "any parameter change" notice — we don't currently change parameters. If the rent price or frequency ever changes (VOP IV / Podmínky IV), the same handler should fire; track as a follow-up.

### C-6 — Withdrawal-form attachment file is `.docx`, not the form embedded in the PDFs

- **VOP VIII.5 + Příloha č. 1**, **Poučení spotřebitele** — both contain the withdrawal form.
- **Current:** `public/documents/formular-odstoupeni-od-smlouvy.docx`. Linked from VOP modal (per audit). `.docx` is fine but uncommon for legal forms; ČOI guidance prefers PDF (universally readable).
- **Fix:** also publish `formular-odstoupeni-od-smlouvy.pdf` and link both, *or* replace `.docx` with PDF. Same for `reklamacni-formular.docx` → PDF. Trivial export from the source `.docx` once.
- **Lawyer note:** the PDF appendix `pouceni-spotrebitele.pdf` already contains a printable withdrawal form on page 2 and a reklamační form on page 3. We could just link to that PDF and skip the standalone forms entirely.

### C-7 — Placeholders in the legal PDFs themselves

These need the **lawyer** (not us) to fix in the source documents, but we should track them and stop linking to the PDFs in their current state if blocking. Items found:

| Doc | Section | Placeholder | What it should be |
|---|---|---|---|
| VOP | I (Ceník definition) | `"____www____"` | URL of price list page |
| VOP | I (Provozní řád definition) | `"____www____"` | URL of operating-rules page |
| VOP | VI.9 | `"z:____www____"` | URL of Podmínky opakovaných plateb |
| VOP | XII.1 | `"www._____.cz"` | URL of privacy policy |
| VOP | XVI.4 | effective date `"____"` | actual effective date |
| Recurring | VIII | `"____www____"` | URL of VOP |

**Recommendation:** before we ship the GoPay activation, the lawyer should produce a corrected VOP and Podmínky with these filled in, then we replace the PDFs in `public/documents/` and bump the displayed effective date. Until then, we should NOT promote these as the live legal docs to GoPay.

### C-8 — Internal inconsistencies between the legal docs

| Field | VOP says | Podmínky opak. plateb says | Footer / current site |
|---|---|---|---|
| Contact phone | +420 774 307 684 (Art. XIII.6) | 774 302 684 (Art. VI) | +420 605 522 566 |
| Withdrawal/contact e-mail | skladmistr@fajnesklady.cz (Art. VIII.7) | simek@fajnesklady.cz (Art. VI / VII) | skladmistr@fajnesklady.cz |
| Contact person | Václav Šimek | Václav Šimek | (not named) |

**Risk:** consumers told to contact two different addresses for the same purpose. ČOI flags this.
**Fix:** lawyer reconciles. We should pick one canonical contact address per purpose (suggest: `skladmistr@fajnesklady.cz` for general contract/withdrawal; `simek@fajnesklady.cz` for recurring-payment-specific issues) and reflect it consistently across documents and the website. Until reconciled, **do not change** what the website displays — but flag this to the lawyer.

### G-1 — Card scheme & 3D Secure logo set is incomplete

- **GoPay rule** (Náležitosti prodejního místa): *"Loga akceptovaných platebních karet a loga 3D-Secure systému (Verified by Visa, MasterCard SecureCode) musí být umístěna na první stránce s informacemi o prodeji."*
- **Current** (`templates/components/payment_logos.html.twig`): Visa, MasterCard, Maestro, "Zabezpečeno GoPay" text. **No 3D Secure logos. No GoPay clickable logo with `<a href="https://www.gopay.com">`.**
- **Fix:**
  1. Add SVG/`<img>` for "Verified by Visa" (now "Visa Secure") and "Mastercard ID Check" (formerly SecureCode).
  2. Wrap GoPay badge in an `<a href="https://www.gopay.com" target="_blank" rel="noopener">…</a>` per GoPay's branding rule.
  3. Render the partial both in the **footer** (already done) and on the **product/place detail page**, the **order form**, and the **order accept page** sticky footer (currently only on order_accept and footer).
- **Asset source:** GoPay provides downloadable logo packs at `https://help.gopay.com/cs/tema/marketing/loga-ke-stazeni`. Download official Visa Secure / Mastercard ID Check / 3D Secure 2.0 SVGs to `assets/images/payment/`.

### G-2 — Apple Pay / Google Pay (if accepted) need their own logos

- Only if we enable wallet payments via GoPay. Decide before launch; if yes, add to `payment_logos.html.twig`. Out of scope until method set is final.

### G-3 — "Zabezpečeno SSL/TLS" wording missing on order-flow pages

- **GoPay rule:** display SSL/TLS encryption notice.
- **Current:** present in footer (`footer.html.twig` line 92: "Komunikace je zabezpečena šifrováním SSL/TLS"), absent on `order_accept.html.twig` and `order_payment.html.twig` themselves.
- **Fix:** small subtle line under the payment-logos block on `order_accept.html.twig` sticky footer and `order_payment.html.twig` head: *"Vaše platba je zabezpečena 256-bit SSL/TLS šifrováním a 3D Secure 2.0."*

### G-4 — Maximum recurring amount (15 000 Kč) is not displayed at consent point

- **Podmínky opakovaných plateb III:** *"Maximální částka opakované platby činí: 15 000 Kč."*
- **Current:** the parameters block on `order_accept.html.twig` shows "Max cap: 3× měsíční platba" — that's the per-spec internal cap (`PriceCalculator::MAX_RECURRING_PAYMENT_MULTIPLIER`), **not** the legal maximum from the document. Two different "max" numbers presented to the customer is confusing and doesn't satisfy the "show the parameters of the recurring payment" rule.
- **Fix:** in the recurring-payment parameters card, display:
  - **Pevná částka:** `{{ totalPrice }} Kč` (this contract's actual amount).
  - **Maximální částka opakované platby:** 15 000 Kč (legal ceiling per the Podmínky).
  - **Frekvence:** Měsíční, vždy ke stejnému dni v měsíci jako první platba.
  - **Doba trvání:** Po celou dobu trvání nájmu / do odvolání souhlasu.
  - **Zrušení:** kdykoliv prostřednictvím e-mailu `<simek@fajnesklady.cz>` (per Podmínky VI).

  Drop "Max cap: 3× měsíční platba" — it's an internal price-calculator constraint, not a customer-facing parameter. If we want to retain a defensive client-side cap, leave the constant in `PriceCalculator` but stop rendering it in the consent UI.

### G-5 — Pricing & VAT statement at every price display

- **GoPay rule:** *"Konečná cena s uvedením měny a stavu DPH."*
- **VOP VI.10 / XV.1:** *"Ceny jsou uváděny včetně DPH."*
- **Current:** prices shown as e.g. `"3 500 Kč / měsíc"`. The "with VAT" status is present in the contract text but **not next to displayed prices** on the place card, order form, or recap card.
- **Fix:** add `"vč. DPH"` or `"(s DPH)"` suffix next to every customer-facing CZK amount on `templates/components/OrderForm.html.twig`, the place / storage-type cards, the order accept recap, and the GoPay payment page. Centralised: introduce a Twig macro `{{ price_with_vat(amount) }}` in `templates/macros/price.html.twig` that returns `"X,XXX Kč vč. DPH"` and use everywhere.

### G-6 — Order-binding pre-payment company identification

- **GoPay rule:** company name + IČO + address visible. **VOP** also requires it on the contract.
- **Current:** present in the contract text (`order_accept.html.twig:214-219`) and footer. Missing on the **payment** page (`order_payment.html.twig`) — the page where the customer enters card details. Recommended for trust + per Náležitosti prodejního místa.
- **Fix:** add a small "Pronajímatel: Mekmann s.r.o., IČO 11678631, Dvořákova 780, 739 11 Frýdlant nad Ostravicí" line above the GoPay inline form on `order_payment.html.twig`.

### G-7 — Contact info on every order-flow surface

- **GoPay rule:** clearly visible contact info (phone + e-mail) on every page.
- **Current:** in footer everywhere (good). Not on the GoPay inline page header.
- **Fix:** the same identification line as G-6 should include phone + e-mail per C-8 reconciliation.

### G-8 — Withdrawal & complaints information accessible from checkout

- **GoPay rule:** withdrawal information + reklamační řád linked from any page describing the sale.
- **Current:** linked from footer, linked from order accept consolidated checkbox modals. **Not linked from `order_form` (the first page the customer sees)** prior to the accept page.
- **Fix:** add a small "Před objednáním si přečtěte: [VOP](#) · [Poučení spotřebitele](#) · [Podmínky opakovaných plateb](#)" block at the bottom of `templates/components/OrderForm.html.twig`, above the submit button. Each link opens the existing modal or the PDF.

### G-9 — ČOI / mimosoudní řešení sporů link

- **Poučení spotřebitele III.2** mandates we point consumers at ČOI for out-of-court dispute resolution.
- **Current:** present inside the modal; not in footer.
- **Fix:** add to footer next to the existing legal links: *"Mimosoudní řešení spotřebitelských sporů — [ČOI](https://www.coi.cz)"*.

### U-1 — Footer-and-PDF route alignment

- The footer's legal links go to internal Twig routes (`public_terms_and_conditions` etc.) that render the partials. The downloadable PDFs in `public/documents/` are referenced inside those Twig partials too. Make sure the **same effective date** appears in the partial and the PDF (currently the partial is hand-maintained Czech text; the PDF is the lawyer's signed master). After the lawyer fixes C-7's placeholders, re-export PDFs and replace the Twig partial bodies with `{{ pdf_to_html }}` or a manually re-typed copy. Track as separate task; don't block GoPay activation on this if the partials are already legally accurate.

### U-2 — Inline-link styling consistency

- Make every legal link inside the order flow use the same `text-accent hover:underline` class. Audit `_term_modal.html.twig`, the partials, and footer.

### U-3 — Wording: "OBJEDNÁVÁM a zaplatím" + "Závazná objednávka"

- After the C-1 button rename, also update screen-reader / `aria-label`, e-mail confirmations that quote the button text, and the help-text under the form.

## Implementation plan (ordered)

The work is large but each item is independent. **Phase 1** must ship together (legal validity); **phase 2** before GoPay sends the activation request; **phase 3** is follow-up.

### Phase 1 — Legal validity (must ship together; no GoPay submission before this is live)

1. **C-1** Rename submit button to **"OBJEDNÁVÁM a zaplatím"** (`order_accept.html.twig:447`; check `customer_signing.html.twig`; check any e-mail copy).
2. **C-2** Add the "Kliknutím na tlačítko … se zavazujete zaplatit" disclosure line above the submit button.
3. **C-3** Split the recurring-payment consent into its own dedicated checkbox below the master consolidated consent, alongside the parameters card. Update Alpine `acceptRecurring` flag + submit gating.
4. **C-6** Convert `formular-odstoupeni-od-smlouvy.docx` and `reklamacni-formular.docx` to PDF (or remove them and link to the existing `pouceni-spotrebitele.pdf` directly — decide).

### Phase 2 — GoPay underwriting prerequisites

5. **G-1** Add Visa Secure + Mastercard ID Check + GoPay-with-link logos to `payment_logos.html.twig`. Render on order form, order accept, order payment, footer.
6. **G-3** SSL/TLS notice on order accept and payment pages.
7. **G-4** Replace the "3× cap" parameter row with the legal 15 000 Kč max + canonical parameter list (purpose, fixed amount, max, frequency, period, cancellation).
8. **G-5** `price_with_vat` macro + apply at every price display.
9. **G-6 + G-7** Identification block (Mekmann s.r.o., IČO, address, phone, e-mail) on `order_payment.html.twig`.
10. **G-8** Pre-purchase legal-doc links on `OrderForm.html.twig`.
11. **G-9** ČOI link in footer.

### Phase 3 — Communications (post-launch follow-up acceptable)

12. **C-4** `RecurringPaymentEstablished` event + confirmation e-mail handler.
13. **C-5** Daily cron `app:notify-upcoming-recurring-charge` for ≥6-month gaps.

### Phase X — Lawyer-owned (parallel, blocking for production submission)

14. **C-7** Lawyer fills placeholders in VOP and Podmínky; we ship corrected PDFs.
15. **C-8** Lawyer reconciles phone numbers and e-mail addresses; we sync footer + identification blocks.

## Files touched (predicted)

- `templates/public/order_accept.html.twig` — items C-1, C-2, C-3, G-3, G-4 (parameter block move + content), G-5
- `templates/public/order_payment.html.twig` — G-3, G-6, G-7
- `templates/components/OrderForm.html.twig` — G-5, G-8
- `templates/components/payment_logos.html.twig` — G-1
- `templates/components/footer.html.twig` — G-9
- `templates/macros/price.html.twig` — **new**, G-5
- `templates/public/_recurring_payments_terms_content.html.twig` — minor sync for C-3 if any text moves
- `assets/images/payment/{visa-secure,mastercard-id-check,3ds-2,gopay}.svg` — **new**, G-1
- `public/documents/formular-odstoupeni-od-smlouvy.pdf` — **new**, C-6
- `public/documents/reklamacni-formular.pdf` — **new**, C-6
- `src/Event/Order/RecurringPaymentEstablished.php` — **new**, C-4
- `src/Event/Order/SendRecurringPaymentConfirmationHandler.php` — **new**, C-4
- `src/Console/NotifyUpcomingRecurringChargeCommand.php` — **new**, C-5
- `templates/emails/recurring_payment_established.html.twig` — **new**, C-4
- `templates/emails/recurring_payment_upcoming_charge.html.twig` — **new**, C-5

## Acceptance

- `composer quality` is green.
- Manual checkout walk-through:
  1. Visit a place page → "vč. DPH" appears next to every price.
  2. Order form bottom shows pre-purchase legal links.
  3. Order accept page: master consent + **separate dedicated** "Souhlasím s opakovanou platbou …" checkbox + parameters block listing 15 000 Kč max, monthly cadence, cancellation contact.
  4. Pre-payment disclosure line "Kliknutím na tlačítko OBJEDNÁVÁM a zaplatím se zavazujete zaplatit" visible above the button.
  5. Submit button reads exactly **"OBJEDNÁVÁM a zaplatím"**.
  6. Submit disabled until master + (if recurring) recurring checkbox + signature + signing place all present.
  7. Footer + payment page show: Visa, Mastercard, Maestro, **Visa Secure**, **Mastercard ID Check**, GoPay-with-`https://www.gopay.com`-link, SSL/TLS notice.
  8. ČOI link in footer.
  9. Withdrawal & complaint forms downloadable as PDF.
- Recurring confirmation e-mail arrives within 2 working days of first successful charge (Phase 3).
- Daily cron logs that pre-charge notice was scheduled when applicable (Phase 3).

## Out of scope

- Rewriting any legal text (VOP, Podmínky, Poučení) — the lawyer's job; we only fix our website to match.
- Apple Pay / Google Pay logos / wallet flows.
- Migrating existing footer-route partials to render directly from PDF.
- Renaming the route `public_recurring_payments_terms` (URL stays).
- The `place.operatingRulesPath` mechanism (separate spec).
- Translating any of this to English/other.

## Decisions (resolved 2026-05-05)

- **Q1 → A1.** Website canonical phone is **+420 605 522 566** (the existing footer number). The mismatched numbers in VOP and Podmínky go to the lawyer for reconciliation in the PDFs. Tracked in [016-lawyer-handoff.md](016-lawyer-handoff.md).
- **Q2 → A2.** Website canonical e-mail for general contact / withdrawal / contracts is **`skladmistr@fajnesklady.cz`**. Recurring-payment-specific cancellation contact stays **`simek@fajnesklady.cz`** (matches Podmínky VI; Václav Šimek is the recurring-payments contact). VOP needs to be updated to align — see lawyer handoff.
- **Q3 → A3.** Both. Standalone PDF files (`formular-odstoupeni-od-smlouvy.pdf`, `reklamacni-formular.pdf`) AND keep printable pages inside `pouceni-spotrebitele.pdf`.
- **Q4 → A4.** Wire it into an admin trigger so price/parameter updates fire the 7-business-day advance notice automatically; daily cron also runs for the ≥6-month-gap case. Both feed the same e-mail handler.
- **Q5 → A5.** Inline list (terse), small print, above the submit button.

The placeholder fixes in the source PDFs (C-7) and the contact-info reconciliation across docs (C-8) are owned by the lawyer; we collected those into [016-lawyer-handoff.md](016-lawyer-handoff.md) for handoff. Locked-in compliance rules are in [.claude/COMPLIANCE.md](../COMPLIANCE.md).
