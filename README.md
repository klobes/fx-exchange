# Emiron FX — Këmbimi valutor (plugin Botble)

Plugin i pavarur për zyrën e këmbimit valutor të licencuar nga Banka e
Shqipërisë. **Nuk varet nga RadiusManager** dhe RadiusManager nuk varet prej tij.

## Faza 1 (0.1.0) — çfarë përmban

| Veçoria | Route | Leja | Menyja |
|---|---|---|---|
| Publikimi i kurseve blerje/shitje | `fx.rates.create` / `fx.rates.store` | `fx.rates.publish` | Këmbimi valutor → Publiko kurset |
| Publikim mbi pragun e shmangies | (i njëjti) | `fx.rates.override` | — |
| Historia e kurseve | `fx.rates.index` | `fx.rates.index` | → Historia e kurseve |
| Kursi zyrtar i BSH-së | `fx.official-rates.*` | `fx.official-rates.index/create` | → Kursi zyrtar i BSH-së |
| Monedhat | `fx.currencies.*` | `fx.currencies.index/create/edit` | → Monedhat |
| Tabela publike + konvertuesi | shortcode `[fx-rates][/fx-rates]` | publike | UI Blocks → "Kurset e këmbimit" |

## Parimet

- **Regjistër i pandryshueshëm** (`fx_rates`, `fx_official_rates`): pa
  `updated_at`; modelet hedhin përjashtim te `updating`/`deleting`
  (`Models/Concerns/Immutable`). Korrigjimi = rresht i ri.
- **Konventa e kursit**: Lek për `unit` njësi valute. `buy_rate` = zyra blen
  valutën; `sell_rate` = zyra shet valutën. Blerja < shitja, gjithmonë.
- **Kontrolli i shmangies**: çdo publikim krahasohet me kursin zyrtar të BSH-së
  të ditës (ose ditës së fundit brenda 10 ditëve). Mbi pragun
  (`config/general.php`, parazgjedhje 3%, `.env FX_DEVIATION_THRESHOLD_PERCENT`)
  kërkohet leja `fx.rates.override` + arsyeja. Shmangia ruhet me kursin.
- **Llogaritjet me bcmath**, asnjëherë float (`FxRateService`).
- **Heqja e plugin-it s'fshin tabelat** (`Plugin::remove()` bosh): libra të rregulluar.
- Lejet kontrollohen me evaluatorin e Botble-s (`fx_can()`), fail-closed.
  Route-t kanë `permission`, controller-at e rikontrollojnë si veprimin e parë.

## Konvertimi (i njëjtë në server dhe në konvertuesin publik)

```
X → ALL : shuma × blerja_X ÷ njësia_X
ALL → Y : shuma ÷ (shitja_Y ÷ njësia_Y)
X → Y   : përmes Lek-ut
```

## Importi i BSH-së dhe njoftimet (0.2.0)

`.env`:

```
FX_ALERT_EMAILS="zyra@domain.al,pronari@domain.al"
FX_DEVIATION_THRESHOLD_PERCENT=3
FX_OFFICIAL_MOVE_ALERT_PERCENT=1
FX_BOA_IMPORT_ENABLED=true
```

- `php artisan fx:import-official --dry-run` — lexon faqen e BSH-së dhe shfaq kurset, pa ruajtur.
- Orari: ditë pune 12:20, 13:00, 13:30 (final). Kërkon `schedule:run` çdo minutë.
- BSH publikon një herë në ditë; luhatjet brenda ditës s'kapen nga ky mekanizëm.

## Referenca e tregut (0.3.0)

`.env` (opsionale):

```
FX_REFERENCE_SOURCES=erapi,ecb
FX_REFERENCE_DIVERGENCE_ALERT_PERCENT=1.5
```

- `php artisan fx:import-reference --dry-run` — shfaq EUR/USD/GBP/CHF sipas çdo burimi.
- Referenca s'shfaqet publikisht. Nëse ndonjëherë shfaqet kursi i ExchangeRate-API në faqe publike, kushtet e tyre kërkojnë atribuimin "Rates By Exchange Rate API".

## Sporteli (0.4.0 — Faza 2a)

Konfigurimi i parë:
1. Këmbimi valutor → **Zyrat**: plotëso emrin ligjor, NIPT-in, licencën e BSH-së, adresën (dalin në kupon).
2. → **Arkat**: krijo arkën dhe cakto arkëtarin (një arkëtar = një arkë).
3. System → Roles: arkëtari `fx.counter.use`; supervizori edhe `fx.counter.void`, `fx.counter.movement`, `fx.sessions.index`, `fx.sessions.approve`, `fx.transactions.index`.
4. Arkëtari → **Sporteli** → numëron gjendjen fillestare → hap arkën.

`.env` (opsionale): `FX_HOLDING_LIMIT_LEK=5000000`, `FX_HOLDING_WARN_PERCENT=90`, `FX_CDD_THRESHOLD_LEK=1000000`, `FX_BOA_INDIVIDUAL_EUR=50000`.

Integriteti: `php artisan fx:verify-ledger` (para çdo inspektimi dhe pas çdo rikthimi nga backup-i).

## Klientët, AML dhe raportet (0.5.0 — Fazat 2b/2c/2d)

Pragjet (`.env`): `FX_ID_THRESHOLD_LEK=100000` (identifikim, `>`), `FX_CDD_THRESHOLD_LEK=1000000` (AIF, `≥` në 24 orë), `FX_BOA_INDIVIDUAL_EUR=50000` (BSH, `≥`).

Rolet e sugjeruara:
- **Arkëtar**: `fx.counter.use`.
- **Supervizor**: + `fx.counter.void`, `fx.counter.movement`, `fx.counter.pep`, `fx.sessions.*`, `fx.transactions.index`, `fx.customers.*`, `fx.reports.*`.
- **Compliance**: `fx.aml.manage`, `fx.customers.index`, `fx.reports.index`.

Raporti ditor: Këmbimi valutor → Raportet → zgjidh datën → kontrollo paraqitjen → "Gjenero librin e BSH-së" → dorëzo → "Shëno të dorëzuar" me nr. protokolli. F3 raporton lëvizjet e ditës (blerë/shitur) për monedhë.

**Çelësi i të dhënave personale (0.5.1):** pas instalimit ekzekuto një herë `php artisan fx:rotate-key` (si pronari i `.env`) — të dhënat kalojnë nga APP_KEY te `FX_DATA_KEY`. Ruaj `FX_DATA_KEY` jashtë serverit (menaxher fjalëkalimesh / zarf i mbyllur): pa të, identifikimet s'lexohen. Mos e ndrysho me dorë; vetëm me `fx:rotate-key`.

## Vënia në punë (0.6.0)

1. Dita provë (shih më sipër), krahasimi i librit të BSH-së me llogaritjen manuale.
2. `php artisan fx:purge-test-data --confirm=Z1` (kurset mbeten; `--with-rates` i fshin edhe ato).
3. FX · Konfigurimi → Festat: shto Bajramet e vitit.
4. Dita e parë reale: hapja e arkës me numërimin fillestar.

## Mbrojtja nga humbja dhe tabela A4 (0.8.4)

Shitje nën koston mesatare të stokut: `off | warn | block` (Burimet → cilësimet, ose `FX_LOSS_GUARD_MODE`). Në `block` kalon vetëm supervizori me lejen `fx.counter.below_cost` + arsye; gjurmohet dhe njoftohet me email. Butoni "Printo kurset (A4)" (sporteli / Publiko kurset) hap `/fx/rates/board` — pa kursin e BSH-së, format A4 në këmbë, vertikal ose horizontal.

## Kursi i rekomanduar nga tregu (0.8.2)

MID i tregut = mediana e burimeve të shënuara "në mesatare" (parazgjedhur vetëm Wise; shto të tjerat pasi t'i testosh te Burimet). BSH = referencë kontrolli. Rekomandimi = MID ± marzhi (mënyra zgjidhet te Burimet: marzhet e publikimit të fundit | % | Lek). Vetëm sugjerim te "Publiko kurset"; banner + email kur tregu lëviz mbi pragun (parazgjedhje 0.5%). Mbi mënyrën si përzgjidhen burimet: `FX_MARKET_*` te CHANGELOG 0.8.2.

## Burimet e kurseve (0.8.1)

FX · Kurset → **Burimet**: statusi i çdo burimi, përpjekja/suksesi i fundit, "Provo tani" (pa ruajtur), "Importo tani", aktivizo/çaktivizo, historia.
Token-et e API-ve (Wise) vendosen te paneli Burimet dhe ruhen **të enkriptuara te DB** (FX_DATA_KEY) — jo te `.env`. `.env` (opsionale): `FX_SOURCE_OUTLIER_PERCENT=15`, `FX_REFERENCE_SOURCES=erapi,ecb,fawaz` (burimet e ndërtuara në kod).
Burimet JSON (FloatRates, Coinbase, Wise, Pasqyra e BSH-së) janë të pa-aktivizuara derisa t'i aktivizosh nga paneli. Vetëm BSH (zyrtar) ndikon te kontrolli i shmangies; të tjerat janë krahasim/verifikim. Komandë: `php artisan fx:import-reference --source=floatrates --dry-run`.

## Kryqëzimi valutë↔valutë (0.8.0)

`.env`: `FX_CROSS_ENABLED=true` (parazgjedhje: i fikur), `FX_CROSS_REPORT_MODE=lek_legs`.

- Sporteli → "Në këmbim të": Lek ose një monedhë tjetër aktive. Një kupon, një rresht te lista; në regjistër janë dy këmbisa të lidhura (blerja e monedhës së dhënë + shitja e monedhës së marrë, me të njëjtën vlerë në Lek).
- Kusuri (diferenca e mbetur) kthehet në Lek (parazgjedhje) ose në monedhën e dhënë nga klienti; shumat kanë maksimumi 2 shifra pas pikës.
- Raportimi i BSH-së: të dyja këmbisat te F1.1/F1.2 (praktika e deritanishme). Anulimi anulon të dyja këmbisat. AIF e numëron vlerën në Lek një herë.

## Ndryshimet e 0.6.1 – 0.7.2

- **Kursi i pa përditësuar:** banner në sportel dhe flamuri `stale_rate` te veprimi; `FX_RATE_STALE_WARNING=false` e fik.
- **Referenca e tregut:** burimi i tretë `fawaz` (`FX_REFERENCE_SOURCES=erapi,ecb,fawaz`) dhe blloku "Nga vijnë këto kurse" te faqja e referencës (vetëm lexim; ndryshimet te `.env`).
- **Arkat e hapura** (FX · Sporteli): gjendja për monedhë e çdo arke me seancë të hapur.
- **Lejet te System → Roles** janë të grupuara si menyja: FX · Sporteli, Kurset, Financat, Compliance, Konfigurimi (emrat "Grupi · Veprimi"). Paneli i ditës: arkat e të gjithëve → `fx.sessions.index`; fitimi/spread → `fx.pnl.index`; likuiditeti dhe partnerët → `fx.bank.index`. Arkëtari me `fx.counter.use` përdor "Paneli im".

## Fazat e ardhshme

- **Faza 2b** — (2a u dorëzua te 0.4.0) regjistri i veprimeve (numër rendor, snapshot i kursit,
  kategoria e klientit sipas Formularit 1 të BSH-së, identifikimi i enkriptuar,
  anulim me arsye), arka për monedhë, kufiri i cash-it, veprimet e lidhura.
- **Faza 3** — Formulari 1 (ditor) dhe Formulari 2 (pozicioni ditor/javor)
  sipas Udhëzimit të BSH-së nr. 3, 22.12.2021; sinjalizimet për AIF-in.
  Pritet formati aktual i formularëve nga BSH para ndërtimit.
