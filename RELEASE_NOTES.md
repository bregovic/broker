# Broker 2.0 - Development History & Release Notes

## [Unreleased] - 2026-08-25
### Fixed — Fio: dvojí započítání obchodů z překrývajících se výpisů
Kdo naimportoval roční i čtvrtletní výpis za totéž období, dostal některé obchody
dvakrát. Ověřeno proti tabulce držených pozic, kterou Fio samo uvádí ve výpisu:
parser hlásil **ČEZ 67 a COLTCZ 90 kusů**, zatímco výpis uvádí **54 a 45**.

Příčinou byl otisk transakce postavený na popisu řádku. Tentýž obchod se ale
v ročním výpisu jmenuje `COLTCZ` a ve čtvrtletním `COLT CZ GROUP SE`, takže
otisky nesouhlasily a deduplikace neproběhla. Otisk teď staví na **ID operace**,
které Fio u každé operace uvádí; popis se používá jen u generace 1, která ID nemá.

Po opravě sedí všechny pozice přesně na výpis: ČEZ 54, CTP 281, COLTCZ 45,
zbytek (ERBAG, KOMB, MONET, O2, TABAK) je doprodaný na nulu.

> **Data na produkci jsou tím pádem nafouklá** a chtějí přeimportovat.

### Fixed — chyběly skutečné výběry z účtu
Řádky `Převod na účet 123-…` se zahazovaly jako „interní převod“, jenže jde
o odchod peněz z účtu — ve vzorku **486 200 Kč**. Regex navíc hlídal jen tvar
„z účtu“, takže „na účet“ vůbec nesedl. Nově se evidují jako výběr, resp. vklad.

Fio také některé řádky uvádí dvakrát (protistrany převodu). Aby se druhý
nezahodil jako duplicita, dostává pořadové číslo v rámci shodných řádků; první
si nechává původní otisk, takže už naimportovaná data zůstávají platná.

### Poznámka — hotovost z toků spočítat nejde
Pokus zrekonstruovat zůstatek na účtu ze součtu pohybů skončil na **−35 359 Kč**
proti **3 267,89 Kč**, které uvádí výpis. Postupně se doplnily převody, poplatky
u obchodů i směny měn a mezera klesla ze 344 tis. na 38 tis., ale nikdy nesedla —
každá oprava odhalila další případ a u ostatních brokerů by se to opakovalo.
Zůstatek se proto bude číst přímo z výpisu („Stav peněžních prostředků“), kde ho
Fio i eToro uvádějí přesně.

## [Unreleased] - 2026-08-24 (k)
### Fixed — zavření endpointů rozbilo stahování cen (regrese)
Nově naimportované tickery zůstávaly bez ceny, takže Bilance ukazovala míň, než
kolik portfolio doopravdy má. Na účtu s eToro chyběly ceny u NWL, VNO a XLK —
dohromady zhruba 1 470 USD, tedy celý rozdíl proti hodnotě, kterou hlásí eToro.

Příčina byla v mé vlastní bezpečnostní úpravě. `setup_dividend_db.php` není jen
údržbový skript, ale zároveň **knihovna, kterou si vtahuje pět dalších souborů** —
`ajax-fetch-history.php`, `ajax_import_ticker.php`, `api-dividend-comparison.php`,
`googlefinanceservice.php` a `v3/install-db.php`. Jakmile v něm byl `require_admin()`,
shodil i běžný požadavek: stahování cen začalo obyčejnému uživateli vracet
„Forbidden: vyžaduje roli admin“.

`auth_guard.php` proto nově hlídá **jen přímé volání přes HTTP**. Když je soubor
vtažený jako knihovna, kontrola se přeskočí — tu už udělal skript, na který
požadavek doopravdy přišel. Ověřeno v obou směrech: přímý požadavek na chráněný
soubor se blokuje, vtažení jako knihovna projde.

## [Unreleased] - 2026-08-24 (j)
### Added — filtr služeb (brokerů) na přehledových stránkách
Přehledy míchají všechny brokery dohromady, takže se z nich nedalo vyčíst, jak si
stojí jednotlivá služba. Nový `ServiceFilter` je v hlavičce stránky a nabízí
vícenásobný výběr brokerů s počtem řádků u každého.

- Zapojeno v **Bilanci, Portfoliu, Dividendách a P&L**.
- Volba se drží v `localStorage` a **platí napříč stránkami** — kdo si zobrazí jen
  Fio, chce ho vidět i v dividendách. Ovládací prvek je proto vždy na stránce
  vidět a při aktivním filtru zvýrazněný; skrytý filtr, který tiše mění čísla,
  je horší než žádný. Vedle je křížek na zrušení.
- Filtruje se **před** předáním do `SmartDataGrid`, takže souhrnné karty
  (počítané z `onFilteredDataChange`) se přepočítají samy — Bilance tedy ukáže
  hodnotu a P&L jen za vybrané služby.
- Prázdný výběr znamená „vše“, ne „nic“, aby stránka nikdy nevyšla prázdná.
  Uložená služba, která v aktuálních datech není (jiný účet, smazaný import), se
  ignoruje. Při jediné dostupné službě se filtr nezobrazuje vůbec.
- Popisky doplněny do `api/v3/translations/{cs,en}.json`.

## [Unreleased] - 2026-08-24 (i)
### Added — eToro Account Statement (XLSX) parser
eToro dává výpis v PDF i XLSX. Sešit je strukturovaný, takže se z něj čte
spolehlivěji; potřebuje rozšíření `zip`, doplněné do Dockerfile jako `php83-zip`.

- Zpracovává se **jen list `Account Activity`**. `Dividends` obsahuje tytéž výplaty
  znovu (navíc s rozpadem srážkové daně), takže by se při čtení obou započítaly
  dvakrát. `Holdings` není seznam transakcí, ale **historie snapshotů** — 86 řádků
  se ukázalo být dvanáct snapshotů po deseti pozicích.
- **Split musí dopočítat parser.** Řádek `corp action: Split` nese poměr jen v textu
  (`XLK/USD 2:1`), `Amount` je 0 a počet kusů chybí. Snapshoty ukazují, co se stalo:
  XLK šel z 0,876795 na 1,75359 kusu a zároveň se půlil otevírací kurz, takže
  pořizovací cena zůstala. Parser proto sleduje počet kusů podle `Position ID`
  a rozdíl připíše s nulovou cenou — pozice splitu odpovídá, cena se nehne.
- Ověřeno proti **finálnímu snapshotu držených pozic od eToro: všech 10 pozic sedí
  přesně**, včetně splitem upraveného XLK. 122 transakcí, žádná kolize otisků.
  Potvrzeno i na produkci přes reálný `analyze` endpoint.
- Pravidlo se hledá podle názvu souboru — XLSX je ZIP a obsah je zkomprimovaný,
  takže se v něm regexem hledat nedá. Prázdný `content_regex` discovery přeskočí.
  `parserFitsFile` zná nově i jmenný prostor `Xlsx`, takže sešitový parser dostane
  jen `.xlsx`/`.xlsm`.

### Fixed — `analyze` vracel u binárních souborů prázdnou odpověď
Nahrání sešitu skončilo HTTP 200 s **nulovou délkou těla**, takže UI nemělo co
zobrazit a import vypadal, že se tiše nic nestalo. Odpověď nese `content_preview`
s prvními 500 bajty souboru; u binárního formátu to není platné UTF-8 a
`json_encode` v takovém případě vrací `false`, takže `echo` vypsalo prázdno.
Náhled se teď u binárního obsahu jen popíše a odpovědi z `analyze` používají
`JSON_INVALID_UTF8_SUBSTITUTE`. Týkalo se to **jakéhokoli binárního formátu**,
nejen eToro.

## [Unreleased] - 2026-08-24 (h)
### Added — Coinbase (CSV) parser
Roční exporty z Coinbase dosud neměly parser. Soubor má pár úvodních řádků a teprve
pak hlavičku `ID,Timestamp,Transaction Type,Asset,Quantity Transacted,…`; částky nesou
symbol měny (`Kč16233.43638`, `-Kč24741.93908`) a měna je vlastní sloupec.

- **Převody nejsou obchod, ale pozici mění.** `Pro Withdrawal` je příchod kryptoměny
  z Coinbase Pro — ve vzorku přinesl **1,2299 BTC**, které se pak postupně prodalo.
  Kdyby se přeskočil jako „ne-obchod“, chybělo by v portfoliu přesně tolik. Bere se
  proto jako nákup v hodnotě, kterou výpis uvádí, a v metadatech je označený
  (`prevod`) — skutečnou pořizovací cenu z Coinbase Pro tenhle export neobsahuje.
- `Exchange Deposit` / `Exchange Withdrawal` (přesun mezi Coinbase a Coinbase Pro,
  27 řádků) se přeskakují. Vklady a výběry ve fiat měně jdou jako hotovost bez
  tickeru, takže z nich import udělá `CASH_EUR` a portfolio je ignoruje.
- Otisk se staví na `ID` z výpisu, ne na hashi hodnot.
- Ověřeno na všech 9 ročních souborech (2018–2026): **52 transakcí**, žádná kolize
  otisků, a výsledná pozice **0,31676066 BTC** sedí přesně na součet, který uvádí
  sám Coinbase.

### Stav na kompletní sadě výpisů (43 souborů, srpen 2026)
Rozpoznáno a zpracováno 31 souborů, 10 je prázdných (roky bez obchodů), nula
fatálních chyb. Nerozpoznané zůstávají už jen **dva soubory eToro** (PDF a XLSX),
pro které parser neexistuje. Napříč 4112 transakcemi ze všech parserů nepřijde
bez tickeru nic než hotovostní pohyby a poplatky, které import doplní sám.

> K dotazu na **BAAPILLE**: prohledal jsem všechny Fio výpisy na disku, dump staré
> databáze i produkční data. Pilulka se nevyskytuje nikde. Burzovní kódy ve všech
> Fio výpisech jsou pouze BAACEZ, BAACTP, BAACZGCE, BAAERBAG, BAAKOMB, BAATABAK
> a BAATELEC.

## [Unreleased] - 2026-08-24 (g)
### Fixed — import padal na NOT NULL u `ticker`, Fio generace 1 nenašla nic
Dvě chyby nahlášené z produkce po prvním ostrém importu.

- **`SQLSTATE[23502] null value in column "ticker"`.** Hotovostní pohyby se neváží
  k žádnému papíru, takže parsery u nich ticker nevyplňovaly — jenže sloupec je
  NOT NULL a shodil se celý import. `v3/api-import.php` teď zástupný kód doplní
  centrálně (`CASH_CZK`, `FEE_CZK`, `TAX_USD`) a hlavně srovná `product_type` na
  Cash/Fee/Tax, které `api-portfolio.php` přeskakuje — z vkladu se tedy nestane
  fiktivní pozice. Papírová transakce, která by ticker mít měla a nemá, se
  přeskočí a započítá do `skipped` místo aby shodila dávku.
  Ověřeno na **3125 transakcích** ze všech parserů a vzorků: bez tickeru přijdou
  jen vklady (37) a poplatky (25), neošetřený případ nula.
- **Fio generace 1 vracela nulu.** Parser byl vyvinutý proti textu z `pdfjs`, ale
  ImportManager tahá obsah přes `pdftotext -layout`, který zachovává vizuální
  pořadí sloupců — u generace 1 tedy úplně jiné. Přepsáno řádkově proti skutečnému
  výstupu pdftotext (sloupce oddělené 2+ mezerami, generace 2 = blok několika řádků
  na transakci, zalomené popisy jako pokračovací řádky).
- Cestou vyšla najevo ještě jedna chyba: test „vypadá to jako sloupec Směr“
  porovnával prefix, takže zahazoval názvy **KOMERČNÍ BANKA** (začíná na `K`) a
  **PHILIP MORRIS** (na `P`) — tedy zrovna kódy směru. Sedm nákupů kvůli tomu
  přišlo o ticker a pozice KOMB a TABAK vycházely záporné.

Revolut a IBKR parsery dávají pod pdftotext **identická čísla** jako předtím,
takže je tahle změna nijak nezasáhla.

## [Unreleased] - 2026-08-24 (f)
### Fixed — české papíry se nikdy neocenily (chybějící symboly pražské burzy)
Bez aktuální ceny zůstávalo Fio portfolio v Bilanci na nule. Yahoo chce pro BCPP
příponu `.PR`, jenže u části papírů se liší i samotný symbol.
- Nový sdílený `api/ticker_symbols.php` s mapou **31 tickerů** ověřenou živým
  dotazem (24. 8. 2026) — všechny vracejí cenu v **CZK**.
- Dosavadní seznam v `ajax-fetch-history.php` lepil `.PR` na kódy `KB`, `PHILIP`
  a `COLT`, které Fio ani `TickerMap` nepoužívají, takže reálně neocenil nic.
- Symbol se neshoduje s kódem u `CZG → COLT.PR` a `CTP → CTPNV.PR`.
- `googlefinanceservice.php` zkouší pražský symbol jako první, jinak by se český
  ticker marně hledal na americkém trhu.
- Bez zdroje zůstávají `O2` (v roce 2021 vytěsněno z burzy, cena neexistuje),
  `ATOMT` a `COLOS` (trh START bez pokrytí) — evidované zvlášť, ať se nezkouší.

Spolu s dřívější opravou, kdy se `live_quotes.currency` konečně používá, tím
české pozice v Bilanci vycházejí správně: kurz CZK je 1, takže cena z burzy jde
do ocenění přímo.

## [Unreleased] - 2026-08-24 (e)
### Added — Fio banka PDF parser (both statement generations)
Fio changed its statement format mid-history, which is why these files never imported.
The generations are told apart by the summary column name, which changed together with
the whole layout:

| | znak | vzorky | tabulka operací |
|---|---|---|---|
| **Generace 1** | `Výnosy z CP` | 2020-01 … 2021-01 (5) | `Objem · Poplatky · Cena · Množství · Směr Trh Název`, datum `05.01.'21 10:14`, bez ISIN, sloupce v PDF **obráceně** |
| **Generace 2** | `Výnosy z IN` | 2021-02 … dosud (19) | `Datum · Název · ISIN · Množství · Objem · Trh · Operace · Jedn. cena · ID operace · Upřesnění · Poplatky · Podíl PNO` |

Pozdější výpisy generace 2 mají navíc `Spisová značka`, ale **tabulka operací je
totožná** — ověřeno na 2024-01, které existuje ve dvou staženích (starším bez značky
a novějším s ní) s identickou hlavičkou. Jeden parser tedy stačí na obě.

- Měna se bere z nadpisu `Výpis operací v <MĚNA>`; jeden výpis má běžně sekci CZK i EUR.
- ISIN → ticker (a názvy → ticker) převzato z `api/js/data/TickerMap.js`, dosud mrtvé
  JS větve. Všech 24 vzorků se namapuje beze zbytku: CEZ, CTP, CZG, ERBAG, KOMB, MONET,
  O2, TABAK.
- **Pozor na čísla u ISINu.** Fio píše tisíce mezerou, takže naivní regex slepí koncovku
  ISINu s množstvím: z `CZ0009093209 350,00` vznikne 209 350 místo 350. Bez lookbehind
  na číslici i písmeno vycházely pozice ve statisících kusů.
- Přeskakují se: převody mezi měnami, převody mezi vlastními účty a distribuce/odebrání
  práv volitelné dividendy (CTP) — ta se připíšou a zase odepíšou a vytvořila by fiktivní
  pozice. `Výplata emisního ážia` se naopak bere jako peněžní výnos (O2 ji platí místo
  dividendy).
- Výsledek na 24 vzorcích: **186 bloků → 124 transakcí**, 62 vynechaných bloků prošlo
  ruční kontrolou (převody měn 24, převody mezi vlastními účty 14, práva volitelné
  dividendy 21, tři nulové náhrady za suspendaci). Žádná kolize otisků uvnitř souboru;
  napříč soubory se překryv čtvrtletních a ročních výpisů odbourá (124 → 117).
- Kontrola správnosti: pozice KOMB, MONET, O2, TABAK i ERBAG vycházejí po všech
  výpisech přesně na **nulu** (plně doprodané), zbývají CEZ 54, CTP 272, CZG 90.
  Na nulu to vyjde jen tehdy, když sedí každý nákup i prodej.

> **Oprava dřívějšího zápisu (verze e).** První verze parseru byla postavená na textu
> z `pdfjs`, jenže produkce používá `pdftotext -layout` a ten dává jiné pořadí sloupců.
> Generace 1 pod ním vracela **nulu** a čísla 121/186 i tvrzení „v archivu chybí část
> historie KOMB“ z toho zápisu neplatila — chyběly řádky, které pdfjs zahodil, nikoli
> výpisy. Parser je přepsaný řádkově proti skutečnému výstupu pdftotext.

## [Unreleased] - 2026-08-24 (d)
### Added — IBKR Transaction History (CSV) parser
IBKR's newest export (`U…TRANSACTIONS….csv`) had no parser; the file was matched on
filename and reported a successful import of zero transactions.

- New `IbkrTransactionHistoryCsvParser`, registered as `ibkr_transaction_history_csv`
  (priority 15, ahead of the Activity Statement rule; their content patterns are disjoint).
- **Currency handling.** In this format `Price` is in the trade currency but
  `Gross Amount`, `Commission` and `Net Amount` are in the account's *base* currency.
  Established two ways: `Gross / (Quantity × Price)` resolves to one rate per day
  (24.040 CZK/USD across all trades on 2024-12-02), and the 2024 dividend total comes to
  2678.9181, exactly the "Total **in CZK**" figure in the Activity Statement for the same
  period. Trades are therefore stored in the **trade currency** (`Quantity × Price`) so the
  original-currency columns and the FX split on the Balance page work, with the commission
  converted into that same currency using the rate implied by the statement. Rows with no
  trade currency (dividends, tax, deposits, fees) keep the base-currency amount as given.
- **The fingerprint includes the commission.** IBKR fills one order in parts and the parts
  can differ *only* in how the commission was split — two INTC buys of 15 shares with the
  same date, price and gross, but fees of 24.07 and 0.03 CZK. Without it the second fill
  would be dropped as a duplicate and 30 shares would land as 15.
- `Forex Trade Component` and `Adjustment` (FX Translations P&L) rows are skipped; they are
  accounting artifacts and would otherwise create tickers like `EUR.CZK` in the portfolio.
- Verified on both sample exports: 744 and 548 rows, no fingerprint collisions, and
  importing both leaves 744 — the overlapping period deduplicates exactly. Net position
  change: INTC +30, LEG +50, NWL +50, NKE +5, RCL −4, SNDK −0.6667.

## [Unreleased] - 2026-08-24 (c)
### Audit of all 58 sample statements — and the fallout
Ran every file in the statement archive through real rule discovery + the real parser
classes. Result before: 24 hard fatals, several files parsed as binary garbage.
Result after: **0 fatals, nothing silently mis-parsed**.

- **Regression fixed (introduced earlier today)**: the `fio_csv` rule added to the seed
  matched Fio **PDF** statements on `Fio banka|ID transakce` and handed all 24 of them to
  `FioCsvParser` → fatal. The rule is removed again: `FioCsvParser` is an unfinished
  stub with a placeholder column mapping, so it should not be registered at all.
- `FioCsvParser` called `$this->cleanNumber()`, which does not exist (`parseNumber` does)
  — it would have fataled on a real Fio CSV too. Fixed, but the column mapping is still
  a placeholder, so the parser stays unregistered.
- **Discovery now respects the file type.** `ImportManager` only offers a `Pdf\*` parser
  a `.pdf` and a `Csv\*` parser a `.csv/.txt/.tsv`. Without it, `.xlsx`, `.xml` and `.htm`
  files matched rules on their filename and parsers churned through binary content
  (one `.xml` produced 26 868 PHP warnings). Those files now fail discovery cleanly.
- **`IbkrCsvParser` imported IBKR's subtotal rows as transactions.** IBKR mixes subtotals
  into the data rows of every section, labelled in the first data cell (`Total`,
  `Total in CZK`, `Total Deposits & Withdrawals in CZK`). On the 2024 statement that was
  10 phantom rows — 4 fake deposits (~60 000 CZK), plus double-counted dividends, tax and
  fees. Now skipped; the file goes from 84 rows to a correct 74.
- **`ibkr_activity_csv` no longer claims `U…TRANSACTIONS….csv`.** That is IBKR's
  *Transaction History* export, a different layout `IbkrCsvParser` cannot read — it was
  matching on filename and reporting a successful import of zero transactions. The rule is
  now scoped to the Activity Statement (`U<acct>_<year>_<year>.csv` / the `DataDiscriminator`
  header). Transaction History files now fail discovery honestly until a parser exists.

### Known gaps confirmed by the audit (no parser exists)
Coinbase (csv/htm/pdf) · eToro (pdf/xlsx) · Revolut consolidated statements (3 files) ·
Revolut crypto/commodity **CSV** · Fio **PDF** (24 files) · all `.xlsx` · IBKR Transaction
History CSV. `IbkrPdfParser` returns 0 transactions on 2 of the 3 IBKR PDFs.

## [Unreleased] - 2026-08-24 (b)
### Fixed — Revolut crypto & commodity imports never ran at all
Verified by replaying rule discovery against the real statements in `example/`:
**every** Revolut crypto and commodity file (9/9 tested, PDF and CSV) was claimed by
the `revolut_trading_pdf` rule and handed to the trading parser.
- **Root cause**: `ImportManager::discoverRule()` returned the *first* matching row of
  `broker_import_rules` with no ordering, and the trading rule matched on generic words
  (`Výpis z účtu`, `Obchod`, `Dividend`) plus a `file_pattern` of `account-statement`,
  which is a substring of `crypto-account-statement`. The crypto/commodity rules matched
  too, but were never reached.
- Rules are now evaluated in `priority` order (lowest first). Priority comes from a new
  `broker_import_rules.priority` column when present, otherwise it is derived from the
  config name — so discovery is fixed on deploy, before `init_broker.php` is re-run.
- Seeded regexes tightened: the trading rule now requires a real trading marker
  (`Transakce v USD`, `Obchod - Market`, …) and `ibkr_pdf` no longer matches the bare
  word `Transactions`. Fixed `kryptomĕnami`/`Smĕněno` (ĕ, breve) → `[ěĕ]`; the seeded
  spelling never matched a real statement.
- Added the missing `ibkr_activity_csv` and `fio_csv` rules to the seed — `ibkr_activity_csv`
  existed only as a hand-made row in the production DB, so a fresh environment lost it.

### Fixed — Revolut crypto parser read the wrong column layout
- In a crypto statement the **date is the last column** of each row
  (`Symbol Typ Množství Cena Hodnota Poplatky Datum`). The parser split the text into
  date-*led* blocks, so every transaction was stamped with the **previous** row's date,
  and its trade regex expected `Nákup BTC` when the statement writes `BTC Nákup`.
- Rewritten to match whole rows anchored on symbol+type at the start and the date at the
  end. On the reference statement it now returns **171/171 rows** (27 buys, 8 sells,
  9 card payments in crypto, 127 staking rewards); it previously returned 127 rewards with
  shifted dates and 2 accidental trades. Verified against three separate statements.
- Card payments settled in crypto (`Platba`) are recorded as disposals (`SELL`) — they
  realize a gain the same way a sale does. Fees are now carried into the DTO.

### Fixed — `parseNumber` turned `0,275` into `275`
- `AbstractParser::parseNumber` treated "exactly 3 digits after a comma" as a thousands
  group, so a crypto quantity of `0,275 ETH` parsed as `275 ETH`. A thousands group is
  never preceded by a bare zero; that case is now a decimal separator.

### Verified, not changed
- `RevolutCommodityPdfParser` was correct all along — it just never got called. Running it
  on the real XAU statement yields all 3 transactions with the right quantities, currencies
  and amounts (closing position matches the statement's own summary exactly).

## [Unreleased] - 2026-08-24
### Fixed — Balance: 4 columns were dead (Avg cost / P&L orig / P&L % orig / FX P&L)
- `BalancePage.tsx` rendered `avg_cost_orig`, `unrealized_orig`, `unrealized_pct_orig`
  and `fx_pnl_czk`, but `api-portfolio.php` never emitted those fields → the columns
  showed `-` / `0` / `0.00 %` / `0` for every position. `total_cost_orig` was already
  being accumulated in the aggregation and then silently discarded.
- `api-portfolio.php` now computes them (plus `avg_cost_czk`):
  - `avg_cost_orig = total_cost_orig / net_qty`
  - `unrealized_orig = (current_price_in_cost_currency - avg_cost_orig) * net_qty`
  - `fx_pnl_czk = unrealized_czk - unrealized_orig * current_rate` — i.e. the CZK
    result minus the price move valued at today's rate, so what's left is the
    currency move on the cost basis.
- **Cost basis unified on one source.** `total_cost_orig` now derives from
  `amount_cur` (was `amount * price`). Since `amount_czk = amount_cur * ex_rate`, the
  original-currency and CZK cost bases can no longer disagree and the FX split is
  exact. Fees are therefore counted exactly as the broker booked them into the
  transaction amount and are *not* added on top — matching `api-pnl.php`, which
  keeps `fees` as a separate term in the realized result. `amount * price` remains
  as a fallback for parsers that leave `amount_cur` empty.
- **Quote currency is now respected.** `live_quotes.currency` was selected but never
  used: the current price was converted with the *transaction* currency's rate. IBKR
  books US stocks in CZK, so those positions were valued with rate 1 on a USD price.
  The quote's own currency is used when a rate exists for it, else the old fallback.
- Positions that mix cost currencies (grouping by ticker across platforms) return
  `null` for the original-currency columns instead of a meaningless number; the grid
  renders `—`.

## [Unreleased] - 2026-05-29
### Fixed — `trans_type` case mismatch (Dividends & P&L were empty)
- Production data stores `trans_type` in UPPERCASE (`DIVIDEND`, `BUY`, `SELL`),
  but the API filtered Title-case literals (`'Dividend'`, `'Buy'`, `'Sell'`) →
  every filtered query returned 0 rows.
- `api-dividends.php`: filter via `UPPER(trans_type) IN (...)`; normalize the
  `type` returned to the frontend to canonical Title-case.
- `api-pnl.php`: match `UPPER(trans_type)` for Buy/Sell.
- Verified against the live DB: 428 dividends and 47 sales now visible (was 0).
- Follow-up (not yet done): `ajax-check-missing-prices.php` and several legacy
  flat-PHP files (`div.php`, `bal.php`, `sal.php`) still use case-sensitive
  filters; the legacy ones query a non-existent `broker_trans` and are dead.

## [Unreleased] - 2026-05-29 (b)
### Fixed — legacy endpoints couldn't connect on Railway (hardcoded MySQL)
- Several frontend-wired endpoints built a hardcoded `new PDO("mysql:...")` and
  relied on a non-existent `api/db.php` → broken on Railway PostgreSQL.
- `ajax-get-chart-data.php`: use `get_pdo()`; fix column `date` → `history_date`
  (verified: returns 502 rows for a real ticker).
- `api-delete-transactions.php`: use `get_pdo()`; fix ticker filter `id` → `ticker`.
- Known remaining: `ajax-update-prices` (id/ticker drift), `api-comments` /
  `ajax-get-user` / `api-dev-history` target tables that don't exist in the
  production schema (`changerequest_*`, `development_history`) — needs schema
  decision before fixing. ~23 dead `broker_*` legacy files pending cleanup.

## [Unreleased] - 2026-05-29 (c)
### Fixed — Helpdesk & Dev History were non-functional on Railway (missing schema)
- The production PostgreSQL DB never had the helpdesk/dev-history tables (only
  core trading tables existed), so RequestsPage / comments / dev-history failed.
- Added `api/sql/helpdesk_schema.sql` — idempotent PG schema for
  `changerequest_log`, `_attachments`, `_history`, `_comments`,
  `_comment_attachments`, `_comment_reactions`, and `development_history`
  (ported from the MySQL `setup_*.php` scripts + consolidated ALTERs).
  Applied to the live Railway DB; all 7 tables verified.
- `api-comments.php`: `get_pdo()` + `GROUP_CONCAT(... SEPARATOR)` → `string_agg`.
- `api-dev-history.php`: `get_pdo()` + `DATE_FORMAT` → `to_char`; added `date` column.
- `ajax-get-user.php`: `get_pdo()` (both connection sites).
- `api-changerequests.php` already used `get_pdo()`; works now that tables exist.

## [Unreleased] - 2026-05-29 (d)
### Fixed — watchlist toggle & price refresh (last broken frontend endpoints)
- `ajax-update-prices.php`: `get_pdo()`; fixed currency subquery
  `transactions.id` → `transactions.ticker` (verified on live DB).
- `ajax-toggle-watch.php`: rewritten to the real contract — `POST {ticker}`
  toggles a row in the `watch` table for the session user (was updating a
  non-existent `broker_live_quotes.track_history`). Mirrors the proven
  `toggle` action in `ajax-manage-watchlist.php`.

## [Unreleased] - 2026-05-29 (e)
### Fixed — import mangled thousands separators (P&L showed fake losses)
- `AbstractParser::parseNumber` always did `str_replace(',', '.')`, so a US-format
  amount like `1,267.00` became `1.267` — values ≥ 1000 were imported ~1000× too
  small. This corrupted large SELL amounts → P&L showed huge fabricated losses
  (e.g. INTC sold at "$0.036" instead of "$36"). Buys/dividends under 1000 were fine.
- parseNumber now detects US (`1,267.00`) vs European (`1.267,50`) formats by
  separator position and treats a lone comma with 3 trailing digits as thousands.
- Re-import the affected statements to correct the data.

## [Unreleased] - 2026-05-29 (f)
### Added — on-demand FX rate sync for valuations
- `rate_sync.php`: `ensure_current_rates()` pulls the ČNB daily fixing into
  `rates` when the newest stored rate is stale (cached — only the first request
  of the day hits ČNB; fails silently if unreachable).
- `api-portfolio.php` calls it so the Balance page values holdings with a current
  CZK rate instead of the last imported one (e.g. USD 21.333 from March → 20.851).

## [Unreleased] - 2026-05-29 (g)
### Added — ticker aliases (unify renamed symbols in reports)
- New `ticker_aliases (alias -> canonical)` table; seeded GOLD -> B (Barrick Gold
  ticker change). Created in prod + added to `init_broker.php`.
- `api-portfolio.php`, `api-pnl.php`, `api-dividends.php` now resolve the report
  ticker via `LEFT JOIN ticker_aliases ... COALESCE(canonical, ticker)`, so a
  renamed security is aggregated as one holding and P&L matches sells under the
  new ticker to buys under the old one. Verified: GOLD+B unify under B.
- Follow-up: auto-detect aliases from statement ISIN (pending ISIN-in-data check).

## [v2.1.0] - 2026-03-31
### Modernization & Railway Deployment
- **Core Refactoring**: Completely overhauled the backend to support PostgreSQL and environment-based configuration via `DATABASE_URL`.
- **Infrastructure**: Added `Dockerfile` and `nginx.conf` for containerized deployment.
- **Directory Structure**: 
  - Backend moved to `/api/`
  - Frontend moved to `/frontend/` (React/Vite)
- **Database Architecture**:
  - Implemented `api/config.php` as a central DB adapter.
  - Added `api/init_broker.php` for easy schema initialization on new environments.
  - Switched from MySQL-specific syntax to cross-driver PDO.
- **Frontend Updates**:
  - Updated API integration to use the new `/api/` prefix.
  - Implemented `AuthContext` and `TranslationContext` with PostgreSQL support.
- **Initialization**: Created a robust setup script that creates necessary tables and a default admin user.

## [v2.0.0] - Legacy Implementation
- Original implementation with MySQL on Wedos.
- Built using PHP 7.4 and React (legacy build).
- Integrated with ČNB for currency rates.
- Basic portfolio and transaction tracking.
