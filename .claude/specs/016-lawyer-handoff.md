# Podklad pro úpravu právních dokumentů — fajnesklady.cz

**Připraveno pro:** advokát/ka spolupracující se společností Mekmann s.r.o.
**Datum:** 5. 5. 2026
**Týká se dokumentů:**

- `vop.pdf` — Všeobecné obchodní podmínky
- `podminky-opakovanych-plateb.pdf` — Podmínky opakovaných plateb
- `pouceni-spotrebitele.pdf` — Poučení spotřebitele

---

## Účel tohoto podkladu

Před spuštěním platební brány GoPay do ostrého režimu připravujeme web fajnesklady.cz tak, aby splňoval všechny zákonné a smluvní požadavky. Při kontrole zveřejněných právních dokumentů jsme nalezli několik **doplnitelných míst (placeholderů)** a **interních nesrovnalostí**, které doporučujeme opravit, aby texty byly konzistentní s webem a s aktuální praxí.

Tento dokument neupravuje obsah práv či povinností smluvních stran — pouze identifikuje konkrétní místa, která je třeba doplnit nebo sjednotit.

---

## 1. Doplnit chybějící údaje (placeholdery v PDF)

V dokumentech jsou ponechána zástupná pole („____www____", podobně), která je nutné nahradit konkrétními URL a daty.

| Dokument | Článek | Aktuální text v PDF | Co doplnit |
|---|---|---|---|
| VOP | I. Definice „Ceník" | „dostupný z _____www_____" | URL stránky s ceníkem (např. `https://www.fajnesklady.cz/cenik`) |
| VOP | I. Definice „Provozní řád" | „dostupný z ____www_____ a také u vstupu do Areálu" | URL stránky s provozním řádem nebo odkaz na PDF |
| VOP | VI. odst. 9 | „Podmínky opakovaných plateb dostupných z: _____www_____" | URL na stránku s Podmínkami opakovaných plateb |
| VOP | XII. odst. 1 | „Zásady ochrany osobních údajů … dostupných na webových stránkách www._____.cz" | URL stránky s ochranou osobních údajů |
| VOP | XVI. odst. 4 | „VOP nabývají účinnosti dnem jejich zveřejnění, konkrétně pak dnem ____" | Konkrétní datum účinnosti VOP |
| Podmínky opakovaných plateb | VIII. | „Smlouvě a VOP, které jsou dostupné zde: _____www_____" | URL na VOP |

> **Webové URL k vyplnění (pokud bude potřeba):** doporučujeme stálé adresy:
> - `https://www.fajnesklady.cz/obchodni-podminky` (VOP)
> - `https://www.fajnesklady.cz/podminky-opakovanych-plateb` (Podmínky opakovaných plateb)
> - `https://www.fajnesklady.cz/pouceni-spotrebitele` (Poučení spotřebitele)
> - `https://www.fajnesklady.cz/ochrana-osobnich-udaju` (GDPR)
> - `https://www.fajnesklady.cz/cenik` (Ceník)

---

## 2. Sjednotit kontaktní údaje napříč dokumenty

Jednotlivé dokumenty uvádějí různé kontaktní údaje pro stejný účel. Webové prostředí používá kanonické hodnoty uvedené níže — prosíme o úpravu PDF tak, aby s webem souhlasily.

### 2.1 Telefonní číslo

| Místo | Aktuální hodnota | Cílová hodnota |
|---|---|---|
| Web (footer, kontakt) | +420 605 522 566 | **+420 605 522 566** (kanonické) |
| VOP, čl. XIII. odst. 6 | +420 774 307 684 | sjednotit na +420 605 522 566 |
| Podmínky opakovaných plateb, čl. VI. (kontaktní osoba) | 774 302 684 | sjednotit na +420 605 522 566 |

### 2.2 E-mailové adresy

| Účel | Web (kanonický) | VOP | Podmínky opak. plateb |
|---|---|---|---|
| Obecný kontakt / odstoupení od smlouvy / reklamace | **skladmistr@fajnesklady.cz** | skladmistr@fajnesklady.cz (čl. VIII odst. 7) ✓ | — |
| Změny / zrušení opakované platby | simek@fajnesklady.cz (zachovat — záměrně oddělené) | — | simek@fajnesklady.cz (čl. VI / VII) ✓ |
| Kontakt pro vady / odstoupení (VOP XIII. odst. 6) | skladmistr@fajnesklady.cz | **simek@fajnesklady.cz** ⚠ — sjednotit | — |

> Doporučení: pro reklamace, odstoupení a obecný kontakt používat `skladmistr@fajnesklady.cz`. Pro změny opakované platby lze ponechat `simek@fajnesklady.cz` (kontaktní osoba pověřená správou opakovaných plateb).

### 2.3 Kontaktní osoba

VOP a Podmínky uvádějí jako kontaktní osobu „Václav Šimek". Ponechat beze změny — pouze sjednotit telefon (viz 2.1).

---

## 3. Doporučení k označení tlačítka „OBJEDNÁVÁM a zaplatím"

VOP, čl. III. odst. 2 uvádí: *„Objednávku odešle Nájemce kliknutím na tlačítko „OBJEDNÁVÁM a zaplatím"."* Web bude tuto formulaci striktně dodržovat (požadavek § 1826a odst. 2 občanského zákoníku — tzv. „tlačítková novela"). Toto pouze potvrzuje, že současné znění VOP je v pořádku — **neměňte ho.**

---

## 4. Doporučení ke vzorovým formulářům (přílohy)

V současné chvíli web nabízí ke stažení formulář pro odstoupení a reklamaci ve formátu `.docx`. Plánujeme je převést na `.pdf` a publikovat oba formáty (či pouze PDF) — odpovídá Příloze č. 1 a Příloze č. 2 VOP / Poučení spotřebitele. Zde se pouze ujišťujeme, zda souhlasíte s tím, že:

- Příloha č. 1 (Formulář na odstoupení od smlouvy) bude publikována jako samostatný PDF soubor.
- Příloha č. 2 (Reklamační formulář) bude publikována jako samostatný PDF soubor.
- Obě přílohy zůstávají součástí Poučení spotřebitele jako tisknutelné stránky uvnitř hlavního PDF.

---

## 5. Co web aktuálně dělá nad rámec dokumentů (informativně)

Pro úplnost — web v rámci procesu objednávky implementuje níže uvedené prvky vyžadované pravidly GoPay a obecnými předpisy. **Nepožadujeme úpravu právních dokumentů kvůli těmto bodům**, jen informujeme:

- Před odesláním objednávky se zákazníkovi zobrazí výslovné upozornění *„Kliknutím na tlačítko OBJEDNÁVÁM a zaplatím odesíláte závaznou objednávku, která zavazuje k zaplacení sjednané ceny."* (§ 1826a odst. 2 OZ).
- Souhlas s opakovanou platbou je vyžadován **samostatným** zaškrtávacím polem (požadavek GoPay — souhlas musí být oddělen od obecných obchodních podmínek). Parametry opakované platby se zobrazují přímo u tohoto pole.
- Maximální výše opakované platby (15 000 Kč dle čl. III. Podmínek) je u parametrů opakované platby zobrazena.
- U každé ceny na webu je uvedeno „vč. DPH".
- Identifikace pronajímatele (Mekmann s.r.o., IČO 11678631, sídlo, zápis do OR) je zobrazena na všech stránkách objednávkového procesu.

---

## 6. Souhrn — doporučené změny ve zdrojových PDF

Aby byly dokumenty plně v souladu s webem, je třeba provést tyto úpravy:

1. Doplnit 5 placeholderů `____www____` ve VOP a 1 v Podmínkách opakovaných plateb (viz oddíl 1).
2. Doplnit datum účinnosti ve VOP, čl. XVI. odst. 4.
3. Sjednotit telefonní číslo na **+420 605 522 566** ve VOP (čl. XIII odst. 6) a v Podmínkách opakovaných plateb (čl. VI).
4. Sjednotit kontaktní e-mail pro odstoupení / vady na **skladmistr@fajnesklady.cz** ve VOP (čl. XIII odst. 6).
5. Po opravě prosíme o nové verze PDF, které nahradíme na webu, a aktualizujeme vyznačené datum účinnosti.

---

*V případě dotazů se prosím obraťte na Václava Šimka — skladmistr@fajnesklady.cz, +420 605 522 566.*
