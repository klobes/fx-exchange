# Emiron FX 0.8.4 — Manuali i instalimit, përdorimit dhe funksionimit

Plugin Botble për zyrën e këmbimit valutor të licencuar nga Banka e Shqipërisë (BSH). Laravel 12 · PHP 8.4 · MySQL.
Ky manual është hartuar nga kodi i versionit 0.8.4. Pjesët e shënuara me ⚠ nuk janë provuar ende në prodhim.

---

## 1. Çfarë bën sistemi

| Fusha | Çfarë ofron |
|---|---|
| **Kurset** | Publikimi i kursit blerje/shitje, historia e pandryshueshme, kontrolli i shmangies nga kursi zyrtar i BSH-së, rekomandimi nga tregu, tabela A4 për sportel |
| **Sporteli** | Blerje/shitje valute, këmbim valutë↔valutë, kupon, anulim, arka për monedhë, furnizim/derdhje, seanca ditore |
| **Compliance** | Identifikimi i klientit mbi limit, PEP, AIF (cash ≥ 1 000 000 Lek / 24 orë), raporti ditor i BSH-së, rastet e dyshimta (AML) |
| **Financat** | Fitim/humbje nga veprimtaria, banka, partnerët (MG, AK-Invest, Sigal…), qarku arka ↔ banka ↔ partnerët |
| **Siguria** | Regjistër i pandryshueshëm me zinxhir hash, të dhëna personale të enkriptuara, gjurmë aksesi, leje të ndara |

---

## 2. Instalimi

### 2.1 Kërkesat
- Botble CMS ≥ 7.5.2, PHP 8.4 me `bcmath`, `zip` (`ZipArchive`), `mbstring`, `intl`; MySQL.
- Cron i Laravel: `* * * * * php artisan schedule:run` (pa të nuk punon importi i kurseve, verifikimi i natës dhe kujtesat).
- Rruga e prodhimit: `/var/www/html/MartFury`.

### 2.2 Hapat
1. Kopjo `emiron-fx-0.8.4.zip` te serveri dhe zhdukose në një dosje të përkohshme.
2. Ekzekuto `bash install.sh` nga dosja `emiron-fx-0.8.4`. Skripti:
   - bën backup të versionit të mëparshëm (`/work/patch-backups/`);
   - kopjon plugin-in te `platform/plugins/emiron-fx`;
   - kontrollon sintaksën (`php -l`), aktivizon plugin-in dhe ekzekuton migrimet;
   - kopjon asset-et publike dhe pastron cache-in;
   - verifikon route-t, orarin, importin nga BSH (`--dry-run`) dhe regjistrin.
3. Te `.env` shto (minimumi):

```
FX_ALERT_EMAILS="zyra@domain.al,pronari@domain.al"
```

4. Si pronari i `.env` (jo si `www-data`), një herë: `php artisan fx:rotate-key`
   - kalon të dhënat personale te `FX_DATA_KEY` të ndarë nga `APP_KEY`;
   - **ruaj vlerën e `FX_DATA_KEY` jashtë serverit**: pa të, identifikimet e klientëve s'lexohen.
5. `php artisan optimize:clear`.

### 2.3 Përditësimi nga një version tjetër
- Zip-i "vetem-skedaret" përmban vetëm skedarët e ndryshuar nga versioni i mëparshëm; kopjoje mbi `platform/plugins/emiron-fx` dhe ekzekuto:

```
php artisan migrate --force --path=platform/plugins/emiron-fx/database/migrations
php artisan view:clear
php artisan optimize:clear
```

- Heqja e plugin-it nuk fshin tabelat (libra të rregulluar).

### 2.4 Variablat `.env` kryesore

| Variabla | Parazgjedhje | Çfarë bën |
|---|---|---|
| `FX_ALERT_EMAILS` | bosh | marrësit e njoftimeve |
| `FX_DEVIATION_THRESHOLD_PERCENT` | 3 | shmangia nga BSH pa leje të posaçme |
| `FX_HOLDING_LIMIT_LEK` | 5 000 000 | kufiri i gjendjes cash të subjektit |
| `FX_ID_THRESHOLD_LEK` | 100 000 | identifikim i detyrueshëm mbi këtë vlerë |
| `FX_CDD_THRESHOLD_LEK` | 1 000 000 | sinjal AIF (24 orë, i njëjti klient) |
| `FX_BOA_INDIVIDUAL_EUR` | 50 000 | rresht individual në raportin e BSH |
| `FX_CROSS_ENABLED` | true | këmbimi valutë↔valutë |
| `FX_LOSS_GUARD_MODE` | block | off / warn / block për shitje nën kosto |
| `FX_MARKET_DEFAULT_MARGIN_LEK` | 0.5 | marzhi i përgjithshëm i rekomandimit |
| `FX_LEK_DECIMALS` | 0 | shifrat dhjetore të Lekut |
| `FX_RATE_STALE_WARNING` | true | paralajmërim për kurs të pa përditësuar |

Token-et e API-ve (Wise) **nuk** vendosen te `.env`: ruhen të enkriptuara te baza, nga paneli Burimet.

---

## 3. Konfigurimi i parë (një herë)

Menyja: **Këmbimi valutor** me pesë grupe — FX · Sporteli, FX · Kurset, FX · Financat, FX · Compliance, FX · Konfigurimi.

1. **Monedhat**: aktivizo EUR, USD, GBP, CHF, CAD etj.; vendos njësinë (1 ose 100) dhe shifrat dhjetore.
2. **Zyrat** (Konfigurimi): emri ligjor, NIPT, licenca e BSH-së, adresa, telefoni. Dalin në kupon dhe në tabelën A4.
3. **Arkat**: krijo arkën dhe cakto arkëtarin (një arkëtar = një arkë).
4. **Festat**: shto Bajramet e vitit.
5. **Banka**: shto llogaritë (emri, IBAN, nr. llogarie, SWIFT, dega, mbajtësi) me gjendjen fillestare reale.
6. **Partnerët**: shto partnerin, monedhat e lejuara, modelin e shlyerjes (parapagim ose llogari me partnerin), pragun e njoftimit dhe shërbimet.
7. **Burimet e kurseve**: vendos token-in e Wise, aktivizo burimet, shëno cilat hyjnë në mesataren e rekomanduar.
8. **Rolet** (System → Roles):

| Roli | Lejet |
|---|---|
| Arkëtar | `fx.counter.use` |
| Supervizor | + `fx.counter.void`, `.movement`, `.pep`, `.below_cost`, `fx.sessions.*`, `fx.transactions.index`, `fx.customers.*`, `fx.reports.*` |
| Admin financiar | `fx.rates.*`, `fx.sources.*`, `fx.bank.*`, `fx.dashboard.index`, `fx.pnl.index`, `fx.partners.manage` |
| Compliance | `fx.aml.manage`, `fx.customers.index`, `fx.reports.index` |

Admin-i i parë mund të jetë super-përdoruesi i Botble (i ka të gjitha lejet).

---

## 4. Përdorimi ditor

### 4.1 Admini / pronari — kurset
1. **FX · Kurset → Publiko kurset**: për çdo monedhë shfaqen kursi BSH, kursi në fuqi, MID i tregut dhe rekomandimi.
2. Rekomandimi = MID i tregut (mediana e burimeve të shënuara) − marzhi për blerje, + marzhi për shitje (parazgjedhje 0.50 Lek). Kliko për ta vendosur në fusha; publikimi mbetet vendimi yt.
3. Nëse kursi del më shumë se 3% nga BSH, kërkohet leja `fx.rates.override` dhe arsyeja.
4. Kurset e publikuara nuk ndryshohen: korrigjimi është publikim i ri.
5. Butoni **Printo kurset (A4)**: formati në këmbë (paloset në mes), vertikal ose horizontal; pa kursin e BSH-së.
6. Kur MID i tregut largohet nga kursi i publikuar më shumë se 0.5%, del banner dhe email.

### 4.2 Arkëtari — sporteli
1. **Sporteli** → numëro gjendjen fillestare për çdo monedhë → **Hap arkën**.
2. **Veprim i ri**:
   - zgjidh "Klienti SHET valutë (ne blejmë)" ose "Klienti BLEN valutë (ne shesim)";
   - zgjidh monedhën, shkruaj shumën; shuma në Lek dhe kursi dalin menjëherë;
   - **Në këmbim të**: Lek (parazgjedhje) ose monedhë tjetër (këmbim valutë↔valutë; kusuri në Lek ose në monedhën e dhënë);
   - kategoria e klientit.
3. **Klienti**: fushat bëhen të detyrueshme vetëm kur vlera kalon limitin e BSH pa dokument identifikimi (100 000 Lek); poshtë tij mbyllen, me lidhjen "Shto klient (opsional)". Kërko klientin me nr. dokumenti; klient i bllokuar, dokument i skaduar ose PEP (pa leje) refuzohen.
4. **Ruaj dhe printo**: veprimi regjistrohet, kuponi printohet nga një iframe i fshehur dhe sporteli mbetet i hapur; rifreskohen vetëm gjendja e arkës, lista e sotme dhe kurset.
5. **Paralajmërimet**: arka s'ka gjendje të mjaftueshme, subjekti kalon kufirin cash, kursi i pa përditësuar, shitje nën kosto.
6. **Kuponi**: një kupon për klientin; në regjistër kryqëzimi është dy këmbisa të lidhura.
7. **Partnerët**: zgjidh shërbimin (p.sh. AK-Invest IN), monedhën, shumën, referencën. Karta "Gjendja e parapaguar te partnerët" tregon sa ka mbetur; bëhet e kuqe nën prag.
8. **Mbyllja e arkës**: numëro cash-in për çdo monedhë; diferenca kërkon shënim dhe e miraton supervizori.

### 4.3 Supervizori
- **Anulim veprimi** (te lista e sotme): arsye ≥ 5 shkronja, vetëm ditën e njëjtë para mbylljes; krijon veprim të kundërt, jo fshirje.
- **Furnizim/derdhje arke**: cash_in ose cash_out me shënim të detyrueshëm. Derdhja lidhet pastaj me lëvizjen bankare.
- **Shitje nën kosto**: në mënyrën `block` kalon vetëm me kutinë "Lejo shitjen me humbje" dhe arsye; regjistrohet dhe njoftohet me email.
- **Arkat e hapura**: gjendja për monedhë e çdo arke; **Seancat**: miraton mbylljen.

### 4.4 Financat
- **Banka**: lëvizje (derdhje/tërheqje cash të lidhura me arkën, mbushje partneri, rimbursim, pagesë, të tjera), konfirmimi kundrejt ekstraktit, kundërveprimi me arsye. Llogaritë ndryshohen me "✎ Ndrysho llogarinë".
- **Partnerët — mbushja e gjendjes së parapaguar**: lëvizja "Mbushje e gjendjes së parapaguar te partneri" (zbret llogarinë bankare, shton gjendjen te partneri). Pagesat utilitare/siguracionet zbriten nga kjo gjendje; komisionet llogariten në fund të muajit.
- **MG**: tërheqja jepet nga arka, partneri na detyrohet; rimbursimi shtohet kur vjen në bankë.
- **Fitim/humbje**: fitimi nga veprimtaria (spread + rivlerësimi), pa shpenzime operative; FX është e liruar nga TVSH.
- **Qarku (Recon)**: arka ↔ banka ↔ partnerët.

### 4.5 Compliance
- **Raportet**: zgjidh datën, kontrollo paraqitjen, "Gjenero librin e BSH-së", dorëzo, "Shëno të dorëzuar" me nr. protokolli. F3 raporton lëvizjet e ditës.
- **AIF**: veprimet cash ≥ 1 000 000 Lek me të njëjtin klient në 24 orë shënohen "AIF: raporto" dhe raportohen ditën pasardhëse të punës.
- **Klientët** dhe **AML**: rastet e dyshimta pezullojnë klientin derisa t'i shqyrtojë compliance.

---

## 5. Si funksionon (rregullat e brendshme)

1. **Kursi**: Lek për njësi valute; blerja < shitja gjithmonë. Zyra BLEN me `blerje`, SHET me `shitje`.
2. **Llogaritja e Lekut**: shuma × kursi ÷ njësia, me bcmath (asnjëherë float), e rrumbullakosur sipas `FX_LEK_DECIMALS`.
3. **Këmbimi valutë↔valutë**: këmbisa 1 = blerja e monedhës A me kursin e blerjes; këmbisa 2 = shitja e monedhës B me kursin e shitjes, me të njëjtën vlerë në Lek. Kusuri kthehet në Lek ose në monedhën e dhënë, pa rrumbullakim në njësi të plota (maksimumi 2 shifra pas pikës). Në "klienti dëshiron b", monedha e dhënë rrumbullakoset lart.
4. **Regjistri i pandryshueshëm**: veprimet dhe kurset nuk përditësohen/fshihen; çdo veprim ka numër rendor (seri FX/P për vit dhe zyrë) dhe hash SHA-256 të lidhur me atë të mëparshmin. `fx:verify-ledger` zbulon boshllëqe ose ndryshime.
5. **Gjendja e arkës** = çdo lëvizje e regjistruar (veprim, furnizim, derdhje) për monedhë; një veprim që e bën negative refuzohet.
6. **Kufiri cash i subjektit**: gjithë arkat në Lek me kursin zyrtar; mbi 90% paralajmërim, mbi 100% bllokim.
7. **Kostoja dhe humbja**: kostoja e stokut është mesatarja e ponderuar; FIFO është planifikuar për 0.8.5. Mbrojtja nga humbja krahason kursin e shitjes me koston.
8. **Rekomandimi**: MID = mediana e burimeve të aktivizuara dhe të freskëta (≥ 3 burime: largohen ato larg medianës > 1%). Marzhi sipas mënyrës (e fundit / % / Lek) me fallback të përgjithshëm 0.50 Lek.
9. **Të dhënat personale**: dokumenti dhe të dhënat e klientit ruhen të enkriptuara (`FX_DATA_KEY`). Kopjet e dokumenteve dhe gjurmët e aksesit regjistrohen.
10. **Njoftimet**: email + banner për shmangie, lëvizje tregu, import të dështuar, regjistër të prishur, nën kosto, gjendje parapagimi të ulët; secili njoftim dërgohet një herë.

---

## 6. Komandat dhe orari

| Komanda | Çfarë bën |
|---|---|
| `php artisan fx:import-official [--dry-run]` | kursi zyrtar nga BSH |
| `php artisan fx:import-reference [--source=…] [--dry-run]` | burimet e tregut |
| `php artisan fx:verify-ledger [--year=] [--notify]` | integriteti i regjistrit |
| `php artisan fx:rotate-key [--dry-run]` | ndërrimi i çelësit të të dhënave |
| `php artisan fx:report-reminders` | kujtesa për raportet e papërfunduara |
| `php artisan fx:purge-test-data --confirm=<kodi i zyrës> [--with-rates]` | pastrimi i të dhënave të provës përpara go-live |

**Orari automatik** (ora e Tiranës, ditë pune): importi i BSH 12:20 / 13:00 / 13:30 (final), referenca 12:25 / 16:40, kujtesa e raportit 09:30, verifikimi i regjistrit 02:30 çdo natë.

---

## 7. Testet

```
vendor/bin/phpunit -c platform/plugins/emiron-fx/phpunit.xml.dist --testsuite Unit
vendor/bin/phpunit -c platform/plugins/emiron-fx/phpunit.xml.dist --testsuite Feature
```

- Feature përdor të njëjtën bazë me prefiksin e tabelave `fxtest_` (nuk preket e dhëna reale); kërkon `APP_ENV=testing`.
- Versionet 0.8.0–0.8.4 janë shkruar pa u ekzekutuar këtu: dërgo rezultatet pas ekzekutimit.

---

## 8. Mirëmbajtja

- **Backup**: `mysqldump` përpara çdo përditësimi dhe pas çdo muaji; ruaj edhe `FX_DATA_KEY`.
- **Pas rikthimit nga backup**: `fx:verify-ledger`.
- **Ndërrimi i çelësit**: vetëm me `fx:rotate-key`; ruaj çelësin e vjetër te `FX_DATA_PREVIOUS_KEYS` derisa të përfundojë.
- **Hyrja në prodhim**: dita provë, krahaso librin e BSH me llogaritjen manuale, pastaj `fx:purge-test-data` (ose skripti `GO-LIVE_pastrimi_i_te_dhenave.sql`), futja e gjendjeve reale dhe dita e parë me numërim real.

---

## 9. Probleme të zakonshme

| Simptoma | Shkaku / zgjidhja |
|---|---|
| Faqja e roleve jep 500 | thellësia e lejeve > 4 (rregulluar në 0.7.3) |
| Sporteli jep ParseError | pamje e vjetër e ruajtur: `php artisan view:clear` |
| S'del furnizimi/derdhja e arkës | mungon leja `fx.counter.movement` |
| "Rekomandimi" del "—" | s'ka burim të shënuar "në mesatare"; kontrollo Burimet (marzhi i përgjithshëm tani e mbulon mungesën e marzheve) |
| Importi i BSH s'sjell kurs | festë ose vonesë e BSH; vendose me dorë te Kursi BSH |
| Token Wise "mungon" | vendose te FX · Kurset → Burimet (jo te `.env`) |
| Gabim migrimi 1553 te `fx_sequences` | migrimi `2026_10_01_000001_prepare_fx_sequences_series` e korrigjon |
| Testet refuzohen në bazën e prodhimit | përdor prefiksin `fxtest_` dhe `APP_ENV=testing` |

---

## 10. Kufizime të njohura

- FIFO për kostot nuk është ende në fuqi (mesatare e ponderuar); planifikuar 0.8.5.
- Formularët F2.1/F2.2 të BSH-së (spot valutë-valutë) nuk janë ndërtuar; kryqëzimi raportohet si dy veprime në Lek te F1.
- Zinxhiri hash nuk përfshin ende flamujt, `rate_id` dhe arsyen e anulimit (planifikuar me version të hash-it).
- Raportet e BSH/AIF, klientët/AML dhe lejet HTTP kanë ende mbulim të pjesshëm me teste.
- Kontrolli i rezultateve (përputhja e totaleve të rillogaritura) është i propozuar, jo i ndërtuar.
