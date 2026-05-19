# GoPay – schvalování opakovaných plateb (kompletní podklad)

**Stav:** P0 změny v kódu hotovy. Email je připraven ke zkopírování. Uživatel přiloží screenshoty + případně doplní URL na předvyplněnou objednávku.

---

## 1. Email pro GoPay – připraven k odeslání

**Předmět:** Re: Aktivace opakovaných plateb – fajnesklady.cz (Mekmann s.r.o., IČO 11678631)

---

Dobrý den,

děkujeme za rychlou reakci a podrobné instrukce. Níže odpovídáme na všechny body z Vašeho e-mailu; objednávkový formulář jsme zároveň aktualizovali tak, aby explicitně obsahoval všechny parametry v pořadí podle Vašeho checklistu.

**1) Typ opakovaných plateb**

Žádáme o aktivaci opakovaných plateb typu **„Na vyžádání"** pro všechny opakované smlouvy – jak pro pronájem na dobu určitou (od 1 měsíce do 1 roku), tak pro pronájem na dobu neurčitou. Důvod této volby: u smluv na dobu určitou bývá poslední úhrada prorátovaná podle počtu zbývajících dní, což standardní automatický cyklus neumožňuje. Volba „na vyžádání" nám zároveň umožňuje před každým strhnutím ověřit, zda je smlouva stále aktivní – to snižuje riziko chargebacků.

**2) Parametry opakované platby v objednávkovém formuláři**

Na stránce „Přijetí smlouvy" (těsně nad odesílacím tlačítkem, mimo obchodní podmínky) zobrazujeme:

- Účel platby: Pronájem skladovací jednotky
- Maximální částka opakované platby: 15 000 Kč
- Částka jednotlivé platby: **fixní** (např. 2 900 Kč / měsíc vč. DPH); u smluv na dobu určitou je poslední doplatek prorátován podle zbývajících dní – zákazník je o této částce písemně informován min. 7 pracovních dní předem
- Frekvence a den strhávání: **fixní**, měsíční, vždy ve stejný den jako první platba; pokud připadne na nepracovní den, strhne se následující pracovní den
- Doba trvání: po celou dobu trvání nájmu / do odvolání souhlasu (doba neurčitá) nebo do konce nájmu (doba určitá)
- Forma komunikace pro změnu nastavení: e-mailem na simek@fajnesklady.cz
- Zrušení a storno: e-mailem na simek@fajnesklady.cz, kdykoli a bez sankce; podrobně viz Podmínky opakovaných plateb, čl. VI

**3) Samostatný checkbox pro souhlas**

Pod uvedenými parametry je samostatný checkbox s textem „Souhlasím s opakovanou platbou v parametrech uvedených výše a s uložením platebních údajů na bráně GoPay." Je vizuálně i logicky oddělený od souhrnného souhlasu s VOP, Poučením spotřebitele a obsahem smlouvy. Bez zaškrtnutí obou checkboxů nelze objednávku odeslat – odesílací tlačítko zůstává neaktivní. Vedle checkboxu je odkaz na úplné znění Podmínek opakovaných plateb.

**4) PCI-DSS Level 1**

Informaci, že GoPay nakládá s údaji platební karty podle mezinárodního bezpečnostního standardu PCI-DSS Level 1, uvádíme přímo pod samostatným checkboxem na stránce „Přijetí smlouvy", v Podmínkách opakovaných plateb (čl. II) a v potvrzovacím e-mailu, který zákazník dostává do 2 pracovních dnů od udělení souhlasu.

**5) Odkaz, na kterém zákazník uděluje souhlas**

Souhlas se zakládá na stránce „Přijetí smlouvy" v objednávkovém průvodci. Stránka je dostupná až po vyplnění objednávkového formuláře, proto Vám v příloze posíláme screenshoty obou variant souhlasového kroku (doba neurčitá + doba určitá).

Pro Vaše posouzení jsme připravili dvě testovací objednávky, na kterých si můžete přímo otevřít stránku „Přijetí smlouvy" se souhlasovým checkboxem. Odkazy směřují na **vývojové prostředí** `fajnesklady.thedevs.cz`, které je obsahově i funkčně identické s produkcí (`fajnesklady.cz`); na produkci se vše překlopí po aktivaci produkční platební brány.

- Objednávka s opakovanou (pravidelnou) platbou – zobrazí blok „Parametry opakované platby" + samostatný checkbox: https://fajnesklady.thedevs.cz/objednavka/019bd1f2-7cec-71f9-a929-dd1749a0a6d5/019c7587-7ffb-7bbc-818f-b6ad135d8fbf/019c85c8-93b0-79f2-aace-ce3cb798f397/prijmout
- Objednávka s jednorázovou platbou (pro srovnání, bez opakované platby): https://fajnesklady.thedevs.cz/objednavka/019bd1f2-7cec-71f9-a929-dd1749a0a6d5/019c7587-7ffb-7bbc-818f-b6ad135d8fbf/019c85c8-9379-7c83-a931-771c3edd6756/prijmout

Další relevantní odkazy:

- Domovská stránka: https://www.fajnesklady.cz
- Podmínky opakovaných plateb: https://www.fajnesklady.cz/podminky-opakovanych-plateb
- Všeobecné obchodní podmínky: https://www.fajnesklady.cz/obchodni-podminky
- Poučení o právech spotřebitele: https://www.fajnesklady.cz/pouceni-spotrebitele

**6) Notifikace a uchování souhlasu**

- Potvrzení o založení opakované platby zasíláme zákazníkovi do 2 pracovních dnů (e-mailem, obsahuje výpis parametrů, PCI-DSS informaci a návod na zrušení).
- Min. 7 pracovních dnů předem zákazníka informujeme, pokud uplynulo více než 6 měsíců od poslední platby nebo pokud se mění parametry opakované platby.
- Záznam o souhlasu (datum, IP adresa, parametry, elektronický podpis smlouvy) uchováváme min. 12 měsíců po ukončení opakované platby.
- Zrušení ze strany zákazníka je možné kdykoli, bez sankce; nemá zpětný vliv na již poskytnuté služby.

**7) Riziko chargebacků**

Bereme na vědomí Vaše upozornění. První platbu zákazník provádí s plnou autentizací (3D Secure 2.0); ke každé následné platbě vedeme záznam o souhlasu, strhnutí i komunikaci se zákazníkem – tyto podklady jsme připraveni doložit při řešení případné reklamace.

V případě dalších dotazů nebo doplnění materiálů nám prosím dejte vědět.

S pozdravem,
Jan Mikeš
Mekmann s.r.o., IČO 11678631
skladmistr@fajnesklady.cz · +420 605 522 566

*Přílohy: 2 screenshoty stránky „Přijetí smlouvy" (varianta na dobu neurčitou + varianta na dobu určitou).*

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
