# Předávací protokol (Handover Protocol)

## Proč tato funkce existuje

Když skončí pronájem skladu, obě strany (nájemce i pronajímatel) potřebují strukturovaně zdokumentovat předání. Bez toho:
- Není záznam o stavu skladu při vrácení
- Pronajímatel nemá možnost oficálně převzít sklad zpět
- Kód zámku se nepřenese na dalšího nájemce
- Nejsou fotografie jako důkaz při případných sporech

## Koncept dvoustranného předání

Předávací protokol má **dvě nezávislé strany**, které je potřeba vyplnit:

### Nájemce (tenant)
- Nahraje fotografie stavu skladu
- Napíše komentář k předání
- Potvrdí, že sklad vyklidil

### Pronajímatel (landlord)
- Zkontroluje a vyfotí stav skladu
- Napíše komentář k převzetí
- Zadá nový kód zámku -- ten se automaticky uloží a použije pro dalšího nájemce

Obě strany mohou vyplnit svou část nezávisle, v libovolném pořadí. Protokol je dokončen teprve když obě strany odevzdají.

## Sklad zůstává obsazený dokud se nedokončí předání

Toto je klíčové rozhodnutí: **sklad se neuvolní při ukončení smlouvy**, ale až po dokončení předávacího protokolu. To zabraňuje tomu, aby si nový nájemce objednal sklad, který ještě nebyl fyzicky zkontrolován a převzat.

Pojistka: pokud protokol není vyplněn do **14 dní po ukončení smlouvy**, sklad se automaticky uvolní a admin dostane notifikaci.

## Časování

- **7 dní před koncem pronájmu**: automaticky se vytvoří předávací protokol a obě strany dostanou email
- **Při ukončení smlouvy**: pokud protokol nebyl vytvořen dříve, vytvoří se jako fallback
- **Každé 3 dny**: připomínky pro strany, které ještě nevyplnily svou část (s eskalující urgencí)

## Tok kódu zámku

Pronajímatel zadá nový kód při převzetí --> uloží se na skladovou jednotku (Storage.lockCode) --> automaticky se použije v příští objednávce pro nového nájemce.

## Technicky

- `HandoverProtocol` entita (OneToOne s Contract) se stavy: PENDING, TENANT_COMPLETED, LANDLORD_COMPLETED, COMPLETED
- `HandoverPhoto` entita pro fotografie od obou stran
- Cron command `app:process-handover-protocols` (denně): vytváří protokoly, posílá připomínky, force-release po 14 dnech
- Domain event `HandoverCompleted` spustí uvolnění skladu a aktualizaci kódu zámku
