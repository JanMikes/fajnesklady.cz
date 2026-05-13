# GoPay – schvalování opakovaných plateb (kompletní podklad)

**Stav:** P0 změny v kódu hotovy. Email je připraven ke zkopírování. Uživatel přiloží screenshoty + případně doplní URL na předvyplněnou objednávku.

---

## 1. Email pro GoPay – připraven k odeslání

> **Komu:** odpovídající adresa GoPay (reply na původní e-mail)
> **Předmět:** Re: Aktivace opakovaných plateb – fajnesklady.cz (Mekmann s.r.o., IČO 11678631)
>
> ---
>
> Dobrý den,
>
> děkujeme za rychlou reakci a za podrobné instrukce. Níže odpovídáme na všechny body z Vašeho e-mailu. Pro úplnost jsme zároveň aktualizovali objednávkový formulář, ať explicitně obsahuje všechny parametry v pořadí podle Vašeho checklistu.
>
> ---
>
> **1) Typ opakovaných plateb**
>
> Žádáme o aktivaci opakovaných plateb typu **„Na vyžádání" (ON_DEMAND)** pro všechny opakované smlouvy — tj. jak pro nájem na dobu určitou s minimální délkou 1 měsíc, tak pro nájem na dobu neurčitou.
>
> Důvod této volby:
>
> - Většina smluv je na dobu neurčitou s měsíční úhradou; část smluv je na dobu určitou (max. 1 rok), kde **poslední úhrada bývá prorátovaná** podle počtu zbývajících dní. Standardní cyklus DAY/WEEK/MONTH (Automatic) by toto neumožnil.
> - Potřebujeme u každého strhnutí ověřit aktuální stav smlouvy (aktivní / vypovězená / pozastavená) a dodržet 3 pokusy retry logiku doporučenou Vaší dokumentací — vlastní cron nám tuto kontrolu umožňuje.
> - Volba ON_DEMAND zároveň snižuje riziko chargebacků: nestrháváme „naslepo".
>
> Technické parametry inicializační platby (`createPayment`):
> - `recurrence.recurrence_cycle = ON_DEMAND`
> - `recurrence.recurrence_date_to = 2099-12-31` (efektivně „bez expirace" — strháváme do odvolání souhlasu zákazníkem nebo do konce nájmu na dobu určitou)
> - Následné platby strháváme přes `createRecurrence($parentPaymentId, amount, orderNumber, description)` jednou měsíčně, vždy ke shodnému dni v měsíci jako první platba.
>
> ---
>
> **2) Parametry opakované platby v objednávkovém formuláři**
>
> Parametry zobrazujeme v samostatném vizuálně odděleném bloku **„Parametry opakované platby"** přímo nad odesílacím tlačítkem objednávky (mimo obchodní podmínky, mimo VOP). Konkrétně:
>
> | Parametr | Hodnota zobrazená zákazníkovi |
> |---|---|
> | Účel platby | Pronájem skladového kontejneru |
> | Maximální částka opakované platby | 15 000 Kč |
> | Částka jednotlivé platby | **Fixní**, např. 2 900 Kč / měsíc (vč. DPH). U smluv na dobu určitou je poslední doplatek prorátován podle počtu zbývajících dní — zákazník je o tom písemně informován min. 7 pracovních dní předem. |
> | Frekvence a den strhávání | **Fixní**, měsíční — vždy ke stejnému dni v měsíci jako první platba (např. 12. v měsíci). Pokud připadne na nepracovní den, strhne se následující pracovní den. |
> | Doba trvání | U doby neurčité: „Po celou dobu trvání nájmu / do odvolání souhlasu zákazníkem." U doby určité: „N plateb do {datum konce nájmu}." |
> | Forma komunikace pro změnu nastavení | E-mailem na **simek@fajnesklady.cz** |
> | Zrušení a storno opakované platby | E-mailem na **simek@fajnesklady.cz**, kdykoli, bez sankce; podrobně viz Podmínky opakovaných plateb (čl. VI) — odkaz dostupný přímo u checkboxu |
>
> ---
>
> **3) Samostatný checkbox pro souhlas s opakovanou platbou**
>
> Pod uvedenými parametry je vizuálně i logicky **oddělený samostatný checkbox** (vlastní vizuální oddělovač, jiná barva pozadí než hlavní souhrnný checkbox s VOP). Text:
>
> > **„Souhlasím s opakovanou platbou** v parametrech uvedených výše a s uložením platebních údajů na bráně GoPay."
>
> Tento checkbox je oddělený od souhrnného souhlasu s VOP, Poučením spotřebitele, GDPR a obsahem smlouvy. Bez zaškrtnutí **obou** checkboxů (souhrnného i tohoto samostatného) **nelze objednávku odeslat** — odesílací tlačítko zůstává neaktivní (DOM `disabled`). Vedle / nad checkboxem je odkaz na úplné znění Podmínek opakovaných plateb (otevírá se v modálu i jako samostatná veřejná stránka).
>
> ---
>
> **4) PCI-DSS Level 1 a uložení platebních údajů**
>
> Souhlas s uložením platebních údajů na straně GoPay je součástí textu samostatného checkboxu (viz výše).
>
> Informaci, že GoPay nakládá s údaji platební karty podle mezinárodního bezpečnostního standardu **PCI-DSS Level 1** (nejvyšší úroveň datové bezpečnosti ve finančním sektoru), uvádíme:
>
> - **přímo pod samostatným checkboxem na stránce „Přijetí smlouvy"** (jako vizuálně oddělený footnote) — text: *„Společnost GOPAY s.r.o. nakládá s údaji Vaší platební karty podle mezinárodního bezpečnostního standardu PCI-DSS Level 1 — nejvyšší úroveň datové bezpečnosti ve finančním sektoru. Čísla karet ani CVV s námi GoPay nesdílí a Pronajímatel k nim nemá přístup."*
> - v Podmínkách opakovaných plateb, čl. II (dostupné z odkazu u checkboxu i jako veřejná URL),
> - v potvrzovacím e-mailu o založení smlouvy (zasíláme do 2 pracovních dnů od udělení souhlasu).
>
> ---
>
> **5) Odkaz, na kterém zákazník uděluje souhlas**
>
> Souhlas se zakládá na stránce **„Přijetí smlouvy"** v objednávkovém průvodci. Stránka je dostupná až po vyplnění objednávkového formuláře (kontaktní údaje, fakturační adresa, typ pronájmu, termíny), proto Vám pro Vaše posouzení posíláme **screenshoty kompletního souhlasového kroku v příloze** (varianta na dobu neurčitou i varianta s pevným koncem).
>
> Veřejně dostupné odkazy:
>
> - **Domovská stránka** (vstup do objednávkového průvodce): <https://www.fajnesklady.cz>
> - **Předvyplněná objednávka pro Vaše posouzení** (souhlasový krok dostupný bez registrace): <!-- TODO: doplnit URL na předvyplněnou objednávku -->
> - **Plné znění Podmínek opakovaných plateb**: <https://www.fajnesklady.cz/podminky-opakovanych-plateb>
> - **Všeobecné obchodní podmínky (VOP)**: <https://www.fajnesklady.cz/obchodni-podminky>
> - **Poučení o právech spotřebitele**: <https://www.fajnesklady.cz/pouceni-spotrebitele>
> - **Ochrana osobních údajů (GDPR)**: <https://www.fajnesklady.cz/ochrana-osobnich-udaju>
>
> Pro průchod *bez* předvyplněné URL: na hlavní stránce stačí kliknout na detail libovolné pobočky → tlačítko **„Pronajmout"** → vyplnit kontaktní údaje a zvolit typ pronájmu **„Na dobu neurčitou"** (pro zobrazení opakované platby) → **„Pokračovat k podpisu"** → zobrazí se stránka **„Přijetí smlouvy"** s blokem „Parametry opakované platby" + samostatným checkboxem.
>
> ---
>
> **6) Notifikace a uchování souhlasu**
>
> Pro úplnost shrnujeme i následné povinnosti, které máme implementovány:
>
> - **Potvrzení o založení opakované platby** zákazníkovi do **2 pracovních dnů** od udělení souhlasu (e-mailem, obsahuje výpis parametrů + PCI-DSS disclosure + návod na zrušení).
> - **Předstih 7 pracovních dnů** před strhnutím platby, pokud uplynulo více než 6 měsíců od poslední úspěšné platby, **nebo** pokud se mění parametry opakované platby (frekvence, cena).
> - **Záznam o souhlasu** (timestamp, IP, parametry, verze Podmínek, identifikace zákazníka, elektronický podpis smlouvy) je uložen v naší databázi minimálně **po dobu 12 měsíců od ukončení opakované platby**.
> - **Zrušení ze strany zákazníka** je možné kdykoli e-mailem na simek@fajnesklady.cz, bez sankce; zrušení nemá zpětný vliv na již poskytnuté služby (per Podmínky čl. VI).
> - **Retry logika**: 3 pokusy o stržení per Vaše doporučení, mezi pokusy 24h; po třetím neúspěšném pokusu opakovaná platba zrušena a zákazník upozorněn e-mailem.
>
> ---
>
> **7) Riziko chargebacků**
>
> Bereme na vědomí Vaše upozornění na zvýšené riziko chargebacků u opakovaných plateb (CVV se neověřuje při následných strhnutích). **První platbu** inicializujeme přes standardní GoPay platební bránu s **3D Secure 2.0**, takže iniciační autorizace probíhá s plnou autentizací držitele karty. Pro následné platby vedeme záznam o souhlasu, jednotlivých strhnutích, e-mailové komunikaci se zákazníkem a stavu smlouvy — všechny tyto podklady jsme připraveni doložit při řešení případné reklamace.
>
> ---
>
> Pokud budete potřebovat jakékoli další informace, doplnění screenshotů z konkrétní varianty průchodu nebo screencast, dejte prosím vědět — obratem doplníme.
>
> S pozdravem,
> **Jan Mikeš**
> Mekmann s.r.o., IČO 11678631
> e-mail: skladmistr@fajnesklady.cz · tel.: +420 605 522 566
>
> *Přílohy: 2 screenshoty stránky „Přijetí smlouvy" (varianta na dobu neurčitou + varianta na dobu určitou).*

---

## 2. TODO – stav po této session

### ✅ DONE (v této session)

- ✅ **Ověřeno z GoPay docs**, že ON_DEMAND nemá roční limit — `recurrence_date_to` < 2099-12-31 platí pro všechny typy (Automatic i ON_DEMAND).
- ✅ **Rozhodnuto: zůstáváme u ON_DEMAND pro vše** (fixed-end ≥ 28 dní i dobu neurčitou). Důvody: kontrola per-charge, prorataní doplatek, unifikovaný retry, nižší chargeback riziko.
- ✅ **`templates/public/order_accept.html.twig`** — restrukturalizovaný blok „Parametry opakované platby":
  - Přidán explicitní řádek **„Částka jednotlivé platby"** s labelem **Fixní** + popis prorataci u fixed-end smluv.
  - Přidán explicitní řádek **„Frekvence a den strhávání"** s labelem **Fixní** + carve-out na nepracovní dny.
  - Přidán nový řádek **„Forma komunikace pro změnu nastavení"** (samostatně od „Zrušení").
  - Vylepšeno **„Zrušení a storno opakované platby"** o odkaz na čl. VI Podmínek a info „kdykoli, bez sankce".
  - Pod samostatný checkbox přidán **footnote s PCI-DSS Level 1 disclosure** (viditelný na consent stránce, ne pouze v modálu).
- ✅ **`.claude/COMPLIANCE.md`** — sekce „Recurring payments" aktualizována o:
  - Explicitní zápis, že používáme `ON_DEMAND` pro VŠE + `recurrence_date_to=2099-12-31`.
  - Závazné pořadí a labely parametrů + povinnost PCI-DSS Level 1 disclosure pod checkboxem.
- ✅ **Ověřeno**, že public URL slugy `/podminky-opakovanych-plateb`, `/obchodni-podminky`, `/pouceni-spotrebitele`, `/ochrana-osobnich-udaju` skutečně existují v kódu (`src/Controller/Public/*Controller.php`).
- ✅ **Twig lint** zelený na všech 144 templates.

### 🔧 Před odesláním emailu (uživatel)

- 🔧 **Pořídit screenshoty** stránky „Přijetí smlouvy" v obou variantách:
  1. Pronájem na dobu neurčitou (otevře blok „Parametry opakované platby" s textem „Po celou dobu trvání nájmu / do odvolání souhlasu").
  2. Pronájem na dobu určitou ≥ 28 dní (otevře blok s textem „N plateb do {datum}" + zmínkou o prorataci).
  Doporučení: zachytit celou stránku včetně checkboxu, PCI-DSS footnote, identifikačního bloku Mekmann a tlačítka „OBJEDNÁVÁM a zaplatím".
- 🔧 **Vyrobit / vybrat předvyplněnou objednávku** pro GoPay reviewera a doplnit URL do emailu na místě označeném `<!-- TODO: doplnit URL na předvyplněnou objednávku -->`. Možnost: vytvořit testovacího zákazníka + objednávku přes admin, vzít odkaz na stránku „Přijetí smlouvy" — ten je platný do expirace objednávky (default 7 dní). Pokud nechcete vyrábět URL, smazat ten odkaz a nechat jen návod „proklikat se z homepage".
- 🔧 **Otestovat průchod end-to-end** na produkci (jeden testovací nákup, nejlépe na malou částku, abyste viděli odeslání potvrzovacího e-mailu o založení opakované platby). Stačí pak vrátit.

### ⏳ P1 – Před schválením aktivace GoPay (po jejich odpovědi)

- [ ] **Sjednotit `_recurring_payments_terms_content.html.twig` čl. III** s novou formulací na order_accept (text o nepracovním dni je teď konzistentní; zkontrolovat, že modal i veřejná stránka mají stejný text). Provedeno z části — pohlídat, zda nebude potřeba další úprava po feedbacku GoPay.
- [ ] **Ověřit obsah potvrzovacího emailu** (`SendRecurringPaymentEstablishedEmailHandler` + `contract_ready.html.twig` nebo dedikovaná šablona) — zkontrolovat, že obsahuje:
  - výpis všech parametrů opakované platby v identickém znění jako na order_accept,
  - PCI-DSS Level 1 disclosure (už tam je: `templates/email/contract_ready.html.twig:173`),
  - návod na zrušení a kontakt simek@fajnesklady.cz,
  - odkaz na Podmínky opakovaných plateb.
  Pokud něco chybí, doplnit.
- [ ] **Manuálně otestovat 7-business-day advance notice** (`bin/console app:send-recurring-payment-advance-notice` nebo přes admin trigger v `AdminContractAdvanceNoticeController`) — vyrobit testovací smlouvu s posledním paymentem > 6 měsíců a ověřit, že notifikace skutečně odejde.
- [ ] **Ověřit záznam o souhlasu v DB** — zkontrolovat, zda u sign-off Smlouvy ukládáme:
  - timestamp,
  - IP adresu zákazníka,
  - hash znění Podmínek opakovaných plateb (verzi, kterou viděl při souhlasu),
  - hodnoty parametrů opakované platby (částka, frekvence, atd.),
  - elektronický podpis a způsob podpisu (typed / drawn).
  Pokud něco chybí (zejména verze Podmínek a IP), doplnit — GoPay nás může auditovat.

### 💭 P2 – Po schválení / dlouhodobé

- [ ] **Self-service zrušení opakované platby v Portálu** — dnes zákazník píše e-mail. Tlačítko „Zrušit opakovanou platbu" v `/portal/...`, které zavolá `voidRecurrence()` + vystaví potvrzení. Sníží support load a vypadá líp v auditu.
- [ ] **Spec 018 – detekce GoPay-side zrušení tokenu** — když zákazník zruší kartu / merchant blacklist u banky, GoPay nám pošle notifikaci. Po aktivaci dořešit, jinak cron failuje a stížnost jde na nás.
- [ ] **Chargeback playbook** (interní dokument) — krátký flow „co dělat při chargebacku": jaké podklady (souhlas, snapshot Podmínek, log strhnutí, e-maily se zákazníkem) přiložit k reklamaci u banky.
- [ ] **Měsíční reconciliation** — cron porovnává naše strhnutí s GoPay reportingem, aby včas zachytil případy, kdy karta expiruje a token přestane fungovat.
- [ ] **Mobilní design parametrů opakované platby** — nové dvouřádkové hodnoty („Fixní, 2 900 Kč / měsíc … Poslední doplatek prorátován…") na úzkém viewportu mohou působit těsně. Otestovat na 360px a případně přepnout layout na vertikální.

---

## 3. Klíčové soubory dotčené v této session

- `templates/public/order_accept.html.twig` — restrukturalizovaný blok „Parametry opakované platby" + PCI-DSS footnote pod checkboxem
- `.claude/COMPLIANCE.md` — sekce „Recurring payments" rozšířena o ON_DEMAND-pro-vše a novou specifikaci parametrů
- (tento dokument) `gopay-recurring-review.md` — kompletní podklad (email + TODO)

Nezměnili jsme: PHP backend, GoPay client, retry logiku, Podmínky opakovaných plateb (samotné PDF/text).
