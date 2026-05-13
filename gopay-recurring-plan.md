# Plán: dotáhnout zbývající GoPay TODO

Po commitu `9954751`. Rozděleno do dvou sprintů a dvou nezávislých skupin: **Sprint A** (musí být před schválením GoPay) a **Sprint B** (po schválení / dlouhodobé). Body jsou seřazeny tak, jak je doporučuju řešit.

---

## Sprint A — Před schválením od GoPay

Cíl: ať reviewer GoPay neuvidí žádný rozpor mezi tím, co jsme mu poslali v emailu, co vidí na webu, co dostane zákazník v potvrzovacím e-mailu a co stojí v Podmínkách.

### A1. Sjednotit potvrzovací e-mail „Opakovaná platba byla úspěšně nastavena" s order_accept ⚠️ **Nejdůležitější**

**Co je v kódu dnes:**
- `templates/email/recurring_payment_established.html.twig` má parametry: Účel / Pevná částka / Maximální / Frekvence / Den stržení / Doba trvání.
- Chybí: explicitní „Fixní" labely, řádek „Forma komunikace pro změnu nastavení", PCI-DSS Level 1 disclosure, fixed-end varianta („N plateb do {date}").
- Handler `SendRecurringPaymentEstablishedEmailHandler.php` neposílá do templatu `isOpenEnded` / `endDate` / `entryCount`, takže šablona vždy renderuje unlimited text — pro fixed-end smlouvu zákazník dostane e-mail s textem „po celou dobu trvání nájmu", což je nepravdivé.

**Co udělat:**
1. **`SendRecurringPaymentEstablishedEmailHandler.php`** — přidat do `->context([…])`:
   - `isOpenEnded` (bool, z `Order` nebo z `PaymentSchedule`)
   - `endDateFormatted` (string|null, z `$order->endDate`)
   - `entryCount` (int|null, jen pokud `isOpenEnded === false`)
   - Nepřidávat dependency na `PriceCalculator` v handleru — `Order::endDate` stačí; entryCount lze dopočítat (`buildPaymentSchedule` nebo přímo na entitě).
2. **`templates/email/recurring_payment_established.html.twig`** — restrukturalizovat `<div class="params">`:
   - Přejmenovat „Pevná částka" → „Částka jednotlivé platby" + value: `Fixní, {amount} Kč / měsíc vč. DPH` + condition pro fixed-end o prorataci doplatku.
   - „Frekvence" + „Den stržení" sloučit do jednoho řádku „Frekvence a den strhávání": `Fixní, měsíční — vždy {debitDay} v měsíci. Pokud připadne na nepracovní den, strhne se následující pracovní den.`
   - „Doba trvání" — větvit na unlimited / fixed-end (`{entryCount} plateb do {endDateFormatted}`).
   - Přidat nový řádek „Forma komunikace pro změnu nastavení" → `simek@fajnesklady.cz`.
   - Zachovat „Zrušení a storno" sekci (už existuje jako `legal-note` blok).
3. **Přidat PCI-DSS Level 1 disclosure blok** (např. další `.legal-note`) za parametry — identický text jako na order_accept.
4. **Integrační test** — `tests/Integration/Event/SendRecurringPaymentEstablishedEmailHandlerTest.php` (pokud existuje), pinout obě varianty (open-ended + fixed-end). Pokud neexistuje, vytvořit minimální testík ověřující, že template se rendruje bez fatal pro obě varianty.

**Odhad:** 30–45 min včetně testů.

---

### A2. Podmínky opakovaných plateb čl. III — explicitně zmínit prorataci doplatku

**Co je v kódu dnes:**
- `templates/public/_recurring_payments_terms_content.html.twig` čl. III: *„Strhávána bude částka fixní, ve výši určené Ceníkem…"* — psáno pro unlimited model. Neuvádí, že u smluv na dobu určitou bude **poslední doplatek prorátován** podle počtu zbývajících dní.
- Order_accept blok i potvrzovací e-mail to zmiňují → vzniká rozpor s Podmínkami.

**Co udělat:**
- Doplnit do čl. III větu: *„U smluv na dobu určitou je poslední úhrada prorátována podle počtu zbývajících dní mezi posledním celým měsícem a koncem nájmu; tato částka je vždy nižší než pravidelná měsíční sazba a zákazník je o ní informován min. 7 pracovních dní předem."*
- Konzistentní změna v PDF verzi `public/documents/podminky-opakovanych-plateb.pdf`? PDF je legal artefakt — pravděpodobně bude vyžadovat aktualizaci od právníka. **Otázka pro uživatele níže.**

**Odhad:** 5 min HTML, neznámo PDF (záleží na právníkovi).

---

### A3. Audit záznamu o souhlasu v DB ⚠️ **Otázka pro uživatele**

**Co je v kódu dnes (`src/Entity/Order.php`):**
- `signaturePath`, `signedAt`, `signingPlace` — ukládáme.
- **Neukládáme** explicitně:
  - IP adresu zákazníka při udělení souhlasu
  - Verzi/hash znění Podmínek opakovaných plateb, který viděl
  - Snapshot parametrů (částka, frekvence, max částka, doba trvání) v okamžiku souhlasu
  - User-agent
  - Hodnoty `accept_recurring_payments`, `accept_vop` apod. jako booly s timestampem

Souhlas je dnes implicitně daný *existencí podepsané smlouvy* (smlouva obsahuje parametry, datum a podpis). To je z pohledu OZ § 1820 dostatečné — zákazník dostal smlouvu a podepsal ji. Z pohledu GoPay auditu / chargeback obhajoby by ale strukturovaný záznam usnadnil dokazování.

**Otázka pro uživatele níže** — zda toto chcete dotáhnout nebo zůstat u smluvního dokumentu jako jediného důkazu.

Pokud ano, scope:
1. Nová tabulka `recurring_payment_consent` (nebo nové sloupce na `Order`): `consent_given_at`, `ip_address`, `user_agent`, `terms_version`, `params_snapshot` (JSONB), `consent_payload` (JSONB s booly).
2. Migrace přes `bin/console make:migration` (NIKDY ručně!).
3. `OrderAcceptController` → po validaci uložit consent payload před commitem (přes nový handler / command).
4. Admin view (jednoduchý read-only) pro dohledání záznamu k objednávce.

**Odhad:** 1–2h.

---

### A4. End-to-end test 7-pracovních-dnů advance notice

**Co je v kódu dnes:**
- `SendRecurringPaymentAdvanceNoticeCommand` (denní cron) + `RecurringPaymentAdvanceNoticeNeeded` event + `SendRecurringPaymentAdvanceNoticeEmailHandler` + template `recurring_payment_advance_notice.html.twig`.
- `ContractRepository::findRequiringAdvanceNotice($now)` rozhoduje, kdo notifikaci dostane.
- `$contract->recordAdvanceNoticeSent($now)` zajišťuje idempotenci.

**Co udělat:**
1. Otevřít `ContractRepository::findRequiringAdvanceNotice` a pochopit přesnou podmínku (>6 měsíců gap, has active recurring, dosud neoznámeno).
2. Ve staging: vytvořit kontrakt, posunout `lastBilledAt` o 6+ měsíců zpět přes migraci/seed, spustit `bin/console app:send-recurring-payment-advance-notice`, zkontrolovat odeslaný e-mail (MailHog / log).
3. Pokud test ukáže díru — opravit.

**Odhad:** 20–40 min, závisí na staging dostupnosti.

---

### A5. Verifikace, že potvrzovací e-mail po první platbě skutečně chodí v produkci

**Co je v kódu dnes:**
- `ProcessPaymentNotificationHandler.php:85` dispatchuje `RecurringPaymentEstablished` event, který chytí handler v A1.

**Co udělat:**
- Provést **jeden testovací nákup na produkci** (malá částka, např. nejlevnější dostupný sklad na 1 měsíc) → potvrdit, že:
  1. GoPay payment success notification přijde.
  2. Handler se spustí.
  3. E-mail dorazí do schránky zákazníka do 2 pracovních dnů (idealně do několika minut).
  4. Po skončení testu nákup stornovat / refundovat.

**Odhad:** 15 min + čekání.

---

## Sprint B — Po schválení / dlouhodobé

### B1. Self-service zrušení opakované platby v Portálu
- Existuje `RecurringPaymentCancelUrlGenerator` (používaný v advance-notice e-mailu) — pravděpodobně už řeší veřejnou URL pro one-click cancel. Ověřit, zda existuje i tlačítko v `/portal/...`.
- Pokud ne: přidat tlačítko + handler `CancelRecurringPaymentCommand`, který zavolá `GoPayApiClient::voidRecurrence()`.
- Odhad: 1–2h.

### B2. Spec 018 — detekce GoPay-side zrušení tokenu
- Backlog spec, oddělená implementace.
- GoPay nám pošle notifikaci, když zákazník zruší kartu / merchant blacklist u banky → potřebujeme webhook handler, který přečte status `CANCELED` na parent payment a označí kontrakt.
- Odhad: 2–4h.

### B3. Chargeback playbook (interní dokument)
- `.claude/CHARGEBACK_PLAYBOOK.md` — krátký flow: jaké podklady (consent record, snapshot Podmínek, billing log, e-maily) přiložit k reklamaci u banky, kdo eskaluje, jak ošetřit refundaci.
- Odhad: 30 min.

### B4. Měsíční reconciliation cron
- Nový command, ideálně 1× denně: vytáhne všechny strhnutí z GoPay reporting API za posledních 24h, porovná s naší `Payment` tabulkou, alertne admina na rozdíly.
- Odhad: 2–3h.

### B5. Mobilní design QA
- Otestovat parametry opakované platby na 360 px viewportu — nové dvouřádkové hodnoty („Fixní, 2 900 Kč / měsíc … Poslední doplatek prorátován…") můžou na úzkém displayi působit těsně. Případně přepnout `flex justify-between` na vertikální layout pod `sm:`.
- Odhad: 15 min.

---

## Doporučené pořadí + závislosti

```
A5 (smoke test produkce)  ───┐
                              │
A1 (sjednotit potvrzovací e-mail)  ──► doporučuji jako PRVNÍ — největší dopad, nejmenší riziko
A2 (Podmínky čl. III HTML)          ──► hned po A1 — 5 min, nulové riziko
A4 (advance notice e2e)             ──► nezávislé, kdykoli
A3 (consent record audit)           ──► nezávislé, ale větší kus + otázka
                                      
B1–B5 (sprint B)                    ──► samostatné, lze řešit ad-hoc / paralelně po schválení
```

**Doporučení:** Pustit nejdřív **A1 + A2** (řešitelné v jedné session, ~45 min, zelený impact pro GoPay reviewera). Potom paralelně otevřít otázku A3 (konzultace, jestli to chcete) a A4 (manuální testing). A5 jako finální sanity check před odesláním emailu.

---

## Otázky pro uživatele

1. **A2 / PDF Podmínek** — chcete úpravu jen v HTML (čl. III na webu) nebo i v PDF `public/documents/podminky-opakovanych-plateb.pdf`? PDF je legal artefakt a typicky vyžaduje aktualizaci u právníka.
2. **A3 / consent record** — chcete dotáhnout strukturovaný záznam o souhlasu (IP, terms_version, snapshot params), nebo nechat smluvní dokument jako jediný důkaz? GoPay to *nepožaduje* explicitně, je to nice-to-have pro audit.
3. **Sprint A — kdy spouštět?** Hned po této session (já implementuju A1+A2)? Nebo počkat až GoPay odpoví na první e-mail?
