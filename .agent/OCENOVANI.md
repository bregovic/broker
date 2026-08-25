---
description: Jak Investyx oceňuje pozice a co se nesmí brát jako pořizovací cena
---

# Oceňování, pořizovací cena a kvalita dat

Tenhle dokument sbírá pravidla, na kterých stojí Bilance a P&L. Většina z nich
vznikla z konkrétních chyb, které se v přehledech projevily statisíci korun —
u každého pravidla je proto uvedený i případ, který ho vynutil.

## 1. Co smí založit pořizovací cenu

**Jen skutečný nákup.** Převod, přesun mezi vlastními účty ani připsání
z korporátní akce pořizovací cenu nezakládají.

### Převod z jiného účtu (`Pro Withdrawal`, `Exchange Deposit`, `Send`/`Receive`)

Výpis u převodu uvádí **tržní hodnotu v den převodu**. To není cena, za kterou
se aktivum pořídilo — ta zůstala u obchodu na zdrojovém účtu, který v tomhle
výpisu vůbec není.

> Coinbase, 30. 11. 2022: převod 1,22992676 BTC oceněný na 485 670,87 Kč.
> Brát to jako nákup znamenalo 394 878 Kč/BTC, tedy zhruba dno cyklu.
> Realizovaný zisk roku 2024 vyšel 1 187 160 Kč místo 788 928 Kč.

Parser takový řádek ukládá s **nulovou částkou** a `metadata.basis_status = UNKNOWN`.
Cenu se pak pokusí odvodit `api/v3/cost_basis.php`:

```
zaplaceno na interní burze = Σ INTERNAL (na_burzu) − Σ INTERNAL (z_burzy)
```

a rozpustí ji mezi převedené pozice v poměru jejich tržní hodnoty. Proto se
`Exchange Deposit` **musí** importovat (jako `trans_type = INTERNAL`,
`product_type = Internal`) — bez něj dopočet nemá z čeho vyjít.

> Ověření: na GDAX odešlo 1 002 407,64 Kč, zpátky nepřišla ani koruna,
> vrátilo se 1,22992676 BTC → 815 014,09 Kč/BTC.

Když odvodit nejde, řádek zůstane `UNKNOWN` a přehledy to **přiznají**.
Nikdy se nesmí sáhnout po tržní ceně převodu jako náhradě.

### Dividenda vyplacená v akciích (volitelná dividenda)

CTP místo peněz připisuje kusy akcií a výpis u nich žádnou částku neuvádí.
Oceňují se **závěrečným kurzem v den připsání** (`ocenit_dividendy_v_akciich()`)
a ta částka slouží dvakrát:

- jako **pořizovací cena** nových kusů,
- jako **dividendový příjem** (vloží se párový řádek `DIVIDEND` s nulovým množstvím).

Nedvojí se to: co je příjem, je zároveň náklad pozice — ekonomicky totéž, jako
by přišla hotovost a hned se za ni akcie koupily.

> U účtu `vac.kral@gmail.com` jde o 8 výplat, 32 kusů CTP, celkem 12 139 Kč.
> Bez toho ležely v portfoliu s nulovou cenou a v dividendách nebyly vidět.

Práva (`Distribuce práv volitelné dividendy` / `Odebrání práv`) se **neimportují** —
netto se vyruší a pozice to není.

## 2. Poplatky patří do pořizovací ceny

Nákupní poplatek zaplatíme, abychom papír získali, takže do nákladu patří.
Platí to v `api-portfolio.php` i v `api-pnl.php` — dřív s ním počítal jen P&L
a oba přehledy si u téže pozice odporovaly.

> CTP: 111 552 + 435,05 = 111 987 Kč. COLT: 30 195 + 115,68 = 30 310,68 Kč.

## 3. `Total`, ne `Subtotal`

Coinbase u obchodu uvádí tři čísla a **žádná dvě nesedí na třetí**.

> Prodej 0,1 BTC z 21. 11. 2024: Subtotal 231 968,45, Total 228 512,09,
> vykázaný poplatek 2 343,15. Rozdíl 3 456,36 Kč poplatek nevysvětluje —
> zbylých 1 113 Kč je spread.

Ukládá se `Total` (částka, která se reálně pohnula na účtu) a `fee = 0`, aby se
poplatek neodečetl podruhé. Rozpad zůstává v metadatech:
`subtotal`, `total`, `vykazany_poplatek`, `execution_cost`, `nevysvetleny_naklad`.

Přehledy pak zvlášť ukazují **execution cost** (poplatek + spread) — tvrdit, že
obchody stály jen vykázaných 15 861 Kč, když jich odešlo 23 397, je zavádějící.

## 4. Měna kotace patří zdroji, ne našim obchodům

V jaké měně je cena ví ten, kdo ji stáhl. Měna z transakcí se použije **jen**
když ji zdroj vůbec nehlásí.

> `BTC-USD` vrací 79 247 USD. Protože většina obchodů s BTC proběhla u Coinbase
> v korunách, ukládalo se to jako CZK a 0,3168 BTC se ocenilo na 25 057 Kč
> místo 518 589 Kč.

**Výjimka: pražská burza.** Tam se naopak mýlí zdroj — Yahoo hlásí měnu
primárního listingu. `bcpp_mena()` proto u papírů z BCPP vynutí CZK.

> `CTPNV.PR` hlásí EUR, ačkoli cena 349,20 je v korunách. 281 kusů se prohnalo
> kurzem eura a portfolio ukázalo 2 482 816 Kč.

Aktuální cena a průměrná cena mohou být v různých měnách — v UI **musí mít
každá u sebe kód měny**, jinak vypadají jako propad.

## 5. Časový test

- **Cenné papíry**: tříletý test platí dlouhodobě.
- **Kryptoměna**: osvobození podle doby držby zavedl až zákon č. 32/2025 Sb.
  s účinností **od 15. 2. 2025**. Na prodeje před tímto dnem se neaplikuje,
  ať se drželo jakkoli dlouho.

Karta „osvobozené (3R+)" se týká **prodaných** kusů, ne držených. Nula na ní
neznamená, že test nikdo nesplnil.

Daňové posouzení konkrétního případu patří poradci — tohle je orientační
rozlišení v přehledu, ne daňový výpočet.

## 6. Neznámé není nula

Stavy v `metadata.basis_status`: `KNOWN`, `ODVOZENY`, `UNKNOWN`.
V portfoliu navíc `price_status`: `KNOWN`, `UNAVAILABLE`.

API je agreguje do `summary.basis_odvozeny` / `basis_unknown` / `bez_ceny`
(portfolio) a `stats.basis_odvozeny` / `basis_unknown` (P&L); UI podle nich
zobrazuje `MessageBar`. Kurzový rozdíl u výpisů vedených rovnou v korunách
hlásí `fx_znamy = false` → „nelze určit", ne 0 Kč.

## 7. Jak ověřovat

**Zlaté pravidlo: proti tabulce držených papírů, kterou uvádí sám broker.**
Fio má „Portfolio CP", eToro Account Summary, Coinbase zůstatek aktiva.
Když sedí množství i u uzavřených pozic na nulu, parser je v pořádku.

> Fio přes všech 31 výpisů: ČEZ 54, CTP 281, COLT 45, ostatní přesně 0.
> Coinbase: 1,22992676 − 0,92992676 + 0,01676066 = 0,31676066 BTC.

**Záporné množství u uzavřené pozice = chybí výpis**, ne chyba algoritmu.

> Účet měl 96 řádků místo 132: ČEZ −66, KOMB −101, MONET −780, TABAK −4.
> Philip Morris proto vycházel s nulovou pořizovací cenou a „ziskem" 66 060 Kč.
> Nešlo o mapování názvu na ticker — to fungovalo — ale o chybějící nákup.

**Druhá kontrola: cashflow.** Ekonomický výsledek musí sednout na
`hodnota pozic + hotovost + výběry − vklady`. U Coinbase to vyšlo do 4 Kč.

### Spuštění skutečných endpointů v CLI

`api-pnl.php` i `api-portfolio.php` čtou `$_SESSION`. Pro ověření nad ostrými
daty se dá session podstrčit — testuje se tím kód, který běží na produkci,
ne jeho opis:

```php
ini_set('session.save_path', $dir);
file_put_contents("$dir/sess_$sid", "user_id|i:$id;role|s:4:\"user\";loggedin|b:1;");
$_COOKIE[session_name()] = $sid;
include 'api/api-pnl.php';   // pozor: oba deklarují resolveUserId(),
                             // v jednom procesu nejdou includnout naráz
```

Připojení k produkční DB bez vypsání hesla:

```
railway run --service "Postgres-AlAr" --environment "investyx 2.0" -- \
  cmd /c "set DATABASE_URL=%DATABASE_PUBLIC_URL% && php skript.php"
```

## 8. Extrakce PDF

Produkce používá `pdftotext -layout` (poppler). Sloupce jsou oddělené dvěma
a více mezerami. **pdfjs dává jiné pořadí sloupců a jako náhrada se použít nedá** —
parser, který na něm vypadal funkčně, na produkci vracel nula transakcí.

Otisk (`brokerTradeId`) staví u Fio na „ID operace". Generace 1 (výpisy do roku
2020) ID nemá, tam se hashuje popis — a ten závisí na verzi poppleru, takže
u těchto řádků hrozí duplicita, pokud se import spustí z jiného prostředí.

## 9. Co zbývá

- **Lotový engine** (FIFO/LIFO/specific-ID). Zatím se počítá průměrnou cenou,
  takže se nesleduje stáří jednotlivých lotů — u CTP to bude potřeba při
  prodeji po dávkách.
- **Import Coinbase Advanced Trade** — teprve on by dal převodu z roku 2022
  skutečnou pořizovací cenu místo odvozené.
- **Ruční doplnění neznámé pořizovací ceny** (`basis_status = RUCNI` už má
  v dopočtu přednost, UI pro zadání chybí).
- **Zobrazení hotovosti** ze stavu uvedeného ve výpisu (rekonstrukce z pohybů
  se ukázala jako nespolehlivá).
- **ISIN jako identita aktiva** místo tickeru. Fio ho ve výpisech uvádí; dnešní
  mapování názvů funguje, ale ISIN je robustnější klíč.
