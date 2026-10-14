# Emiron FX — CHANGELOG

## [0.8.4] — 2026-10-15 — "Mbrojtja nga humbja në shitje; tabela e kurseve A4 për sportel"

### U shtua
- **Mbrojtja nga humbja** (`LossGuardService`): kur zyra SHET një valutë me kurs më të ulët se **kostoja mesatare e stokut** (blerë më shtrenjtë), shitja është me humbje. Mënyra zgjidhet te FX · Kurset → Burimet → "Mbrojtja nga humbja" (ose `FX_LOSS_GUARD_MODE`, parazgjedhje `block`):
  - `off` — pa kontroll; `warn` — lejohet, paralajmërim në ekran + flamuri "Nën kosto"; `block` — refuzohet, kalon vetëm nga përdoruesi me lejen e re **`fx.counter.below_cost`** (supervizor), me kutinë "Lejo shitjen me humbje" dhe arsye ≥ 5 shkronja.
  - Çdo shitje nën kosto që kalon regjistrohet te gjurmët e aksesit (`below_cost_sale`) dhe dërgon email (`below_cost`, një herë për veprim). Vlen edhe për këmbisën 2 të kryqëzimit valutë↔valutë (shitet monedha B).
  - Te sporteli paralajmërimi del menjëherë kur shkruhet shuma (kosto, kurs); serveri vendos përfundimisht. Nuk ka kontroll kur s'ka stok me kosto (p.sh. vetëm gjendje hapëse).
  - Kostoja = mesatarja e ponderuar e stokut (FxPnlService); kur FIFO të hyjë (0.8.5) ndërrohet vetëm `costPerUnit()`.
- **Printo kurset (A4)**: butoni te sporteli dhe te "Publiko kurset" hap `FX · Kurset → /fx/rates/board`: tabelë e madhe Monedha / BLEJMË / SHESIM me datën dhe orën e publikimit, **pa kursin e BSH-së**. Formate: **A4 në këmbët** (paloset në mes; gjysma e sipërme del me kokë poshtë për klientin), A4 vertikal, A4 horizontal. Leje: `fx.rates.index` ose `fx.counter.use`.

- **"Ruaj dhe printo" pa ndërruar faqe**: veprimi dërgohet me AJAX; kuponi printohet nga një iframe i fshehur (sporteli mbetet i hapur, pa faqe kuponi që "ngec" pas printimit). Pas çdo veprimi (edhe anulimi) rifreskohen vetëm gjendja e arkës, lista e veprimeve të ditës dhe kurset/kostot, forma pastrohet dhe kursori kthehet te shuma. Gabimet (p.sh. nën kosto, gjendje e pamjaftueshme) shfaqen sipër pa humbur të dhënat e shkruara. Pa JavaScript funksionon si më parë (redirect te kuponi).
- **Gjendja e parapaguar te partnerët te sporteli**: kartë "Gjendja e parapaguar te partnerët" (kolona djathtas) me gjendjen e mbetur në Lek (dhe monedhat e tjera) për çdo partner me shërbim të parapaguar; rreshti bëhet i kuq me "E ULËT — kërko mbushje nga banka" kur bie nën pragun e partnerit (`prepaid_low_alert`). Rifreshkohet pas çdo veprimi me partner.
- **Rifreskim vetëm i tabelës përkatëse**: furnizimi/derdhja e arkës dhe veprimet/anulimet me partnerët ruhen gjithashtu me AJAX. Rifreskohen vetëm seksionet e prekura (gjendja e arkës, kufiri i gjendjes, veprimet e sotme, veprimet me partnerët), jo e gjithë faqja.
- Rregullim: `@json(trans(..., [...]))` te sporteli shkaktonte ParseError — etiketat llogariten te `@php`.

- **Banka: më shumë të dhëna dhe redaktim i llogarisë**. Përveç emrit dhe IBAN tani ruhen: nr. i llogarisë, SWIFT/BIC, dega, mbajtësi, personi dhe telefoni i kontaktit, shënim. Butoni "✎ Ndrysho llogarinë" te çdo kartë hap faqen e redaktimit (leja `fx.bank.manage`); mund të çaktivizohet llogaria. Monedha dhe gjendja fillestare bllokohen sapo llogaria ka lëvizje (nuk prishet historiku). Ndryshimet regjistrohen te gjurmët (`bank_account_created/updated`). IBAN/SWIFT normalizohen (pa hapësira, shkronja të mëdha). Migrim i ri `2026_10_15_000012_add_details_to_fx_bank_accounts` (idempotent, vetëm kolona të reja opsionale).

- **Rekomandimi me marzh të përgjithshëm (mbrojtje nga lëvizja e kursit)**: kolona "Rekomandimi" dilte "—" kur mënyra e zgjedhur s'kishte bazë (p.sh. "marzhet e publikimit të fundit" pa publikim kundrejt BSH, ose "Lek" pa marzhe për monedhën). Tani, në atë rast, zbatohet **marzhi i përgjithshëm**: blerja = MID − 0.50 Lek, shitja = MID + 0.50 Lek (parazgjedhje; ndryshohet te Burimet → "Marzhi i përgjithshëm", blerja dhe shitja veç e veç; ose `FX_MARKET_DEFAULT_MARGIN_LEK`). Marzhi i vet i monedhës dhe mënyra e zgjedhur kanë përparësi.

### Teknike
- Leje e re `fx.counter.below_cost` (nën `fx.counter.use`; thellësia e pemës ≤ 4). Rregulla kërkese `allow_below_cost`, `below_cost_reason`. Cilësimi `loss_guard_mode` ruhet te cilësimet e tregut.
- Teste: `LossGuardTest` (6): blokim, kalim mbi kosto, warn+flamur, off, pa stok, kërkesa e override (leje + arsye).
- Një migrim i ri (kolonat e llogarisë bankare); `install.sh` e ekzekuton.

## [0.8.3] — 2026-10-14 — "Token-i i API-së i enkriptuar te DB; kryqëzimi aktiv; fushat e klientit të detyrueshme vetëm mbi limitin e BSH"

### U ndryshua
- **Token-i i API-së (Wise) ruhet te baza, i ENKRIPTUAR** (kolona `fx_rate_sources.secret`, FxCrypto / `FX_DATA_KEY`) — jo te `.env`. Vendoset te FX · Kurset → Burimet (fushë password, leja `fx.sources.manage`); **dekriptohet vetëm në momentin e kërkesës HTTP**. Nuk shfaqet kurrë (vetëm "i vendosur / mungon"), nuk shkruhet në regjistër/log/përgjigje dhe nuk serializohet nga modeli. Vendosja/heqja regjistrohen te gjurmët e aksesit. `fx:rotate-key` e rienkripton token-in me çelësin e ri. **`FX_WISE_TOKEN` te `.env` nuk përdoret më** — vendose token-in te paneli (dhe heqe nga `.env`).
- **Kryqëzimi valutë↔valutë është aktiv parazgjedhur** (`FX_CROSS_ENABLED=false` e fik). Te sporteli del rreshti "Në këmbim të" me Lek si parazgjedhje dhe monedhat e tjera aktive; një kupon, dy këmbisa të lidhura në regjistër (shih 0.8.0).
- **Fushat e klientit te sporteli** (dokumenti, emri/mbiemri/emri ligjor te klienti i ri) shfaqen si **të detyrueshme VETËM kur vlera në Lek e këmbimit (edhe për kryqëzim) kalon limitin e BSH pa dokument identifikimi** (`FX_ID_THRESHOLD_LEK`, parazgjedhje 100 000). Poshtë limitit bllokut të klientit është i mbyllur, me lidhjen "+ Shto klient (opsional)". Serveri e zbaton gjithsesi rregullin.

### Teknike
- Migrim i ri `2026_10_14_000011_add_secret_to_fx_rate_sources`. `SourceRegistry::secret()` dekripton lazily; descriptor-i i burimit ka vetëm `needs_secret` / `has_secret`. `FxRateSource::$hidden = ['secret']`. Config `wise_token` u hoq.
- Teste të reja: `SourceSecretTest` (8): i enkriptuar në DB, nuk del në descriptor/model/log/përgjigje, Bearer i saktë vetëm kur përdoret, dështim pa rrjedhje, token që mungon pa kërkesë. Teste ekzistuese u përshtatën. Gjithsej 167 teste.

### Kujdes pas instalimit
- Ekzekuto migrimet. Te Burimet vendos token-in e Wise (një herë); pastaj hiqe `FX_WISE_TOKEN` nga `.env` nëse e kishe vendosur.
- Nëse `FX_DATA_KEY` ndryshon pa `fx:rotate-key`, token-i s'dekriptohet më: vendose përsëri te paneli.

## [0.8.2] — 2026-10-13 — "Kursi i rekomanduar nga tregu: Wise si burim kryesor, mediana e burimeve të zgjedhura, marzhe të zgjedhshme dhe njoftim lëvizjeje"

### Modeli (BSH = referencë kontrolli; tregu = baza e rekomandimit)
- **MID i tregut** për monedhë = **mediana** e burimeve të shënuara "në mesatare" (**parazgjedhur vetëm Wise**). Të tjerat (Currency-API, FloatRates, Coinbase…) shfaqen si krahasim derisa t'i shënosh vetë pasi të jenë testuar. Para medianës hidhen burimet e vjetra (> `FX_MARKET_MAX_AGE_DAYS`, parazgjedhje 2) dhe, kur ka ≥ 3 burime, ato që largohen nga mediana mbi `FX_MARKET_OUTLIER_PERCENT` (1%). Numri minimal i burimeve vendoset nga paneli.
- **BSH** mbetet referenca e kontrollit: shmangia e MID-it kundrejt BSH-së shfaqet dhe kontrolli i shmangies së kurseve të zyrës s'ndryshon; BSH nuk hyn në mesatare.
- **Kursi i rekomanduar i zyrës** = MID ± marzhi, me mënyrë të **zgjedhshme** (cilësim te paneli): *marzhet e publikimit të fundit kundrejt BSH* (parazgjedhje) · *% fikse për monedhë* (blerje poshtë MID, shitje sipër) · *Lek fikse për monedhë*. Blerja duhet të jetë < shitja, përndryshe s'ka rekomandim.
- **Vetëm sugjerim:** asgjë s'publikohet vetë. Te "Publiko kurset" shfaqet MID (me burimet), shmangia kundrejt BSH dhe butoni "Rekomandim tregu: blerje / shitje" që mbush fushat; sugjerimi i vjetër (kundrejt BSH-së) mbetet si alternativë.

### Njoftimi i lëvizjes
- Kur MID largohet nga **mesi i kursit të publikuar** (blerje+shitje ÷ 2) mbi pragun (parazgjedhje 0.5%, i ndryshueshëm): **banner** te "Publiko kurset", kolona "Lëvizja" te Burimet, dhe **email** te `FX_ALERT_EMAILS` (një herë për gjendje/ditë). Kontrollohet pas çdo importi të vërtetë të një burimi (jo pas "Provo tani").

### E shtuar
- Te **FX · Kurset → Burimet**: kolona "Në mesataren e rekomanduar" (përfshi/hiq), tabela e kursit të tregut (MID, burimet, kundrejt BSH, kursi i publikuar, lëvizja, rekomandimi) dhe forma e cilësimeve (mënyra e marzhit, pragu, burime minimale, marzhet për monedhë). Lejet: `fx.sources.manage`.
- Migrim i ri `2026_10_13_000010_create_fx_market_tables` (`fx_source_settings` me Wise të shënuar, `fx_market_margins`, `fx_market_settings`). Kod i ri: `MarketRateService`; `FxAlertService::marketMoved`.
- `.env` (opsionale): `FX_MARKET_MARGIN_MODE`, `FX_MARKET_DRIFT_ALERT_PERCENT`, `FX_MARKET_MIN_SOURCES`, `FX_MARKET_MAX_AGE_DAYS`, `FX_MARKET_OUTLIER_PERCENT`, `FX_MARKET_DECIMALS`.
- Teste të reja: `MarketRateTest` (13): Wise i vetëm, mediana, outlier, burim i vjetër, minimumi, tri mënyrat e marzhit, rekomandim i pavlefshëm, lëvizja, njoftimi një herë, hook-u pas importit, prova pa njoftim. Gjithsej 159 teste.

### Kujdes pas instalimit
- Aktivizo te Burimet **Wise** (me `FX_WISE_TOKEN` te `.env`) para se të presësh MID. Pa burim të aktivizuar dhe të shënuar "në mesatare", rekomandimi mungon dhe punon sugjerimi i vjetër.
- Mënyra "marzhet e publikimit të fundit" kërkon që të ketë një publikim të mëparshëm me shmangie të ruajtur kundrejt BSH-së.

## [0.8.1] — 2026-10-12 — "Qendra e burimeve të kurseve: statusi i dukshëm, regjistri i përpjekjeve dhe burime JSON me URL"

### E shtuar
- **FX · Kurset → Burimet** (`fx.sources.index` / `fx.sources.manage`): një faqe me çdo burim kursesh — roli (Zyrtar | Referencë tregu | Pasqyrë e BSH-së), shëndeti (në rregull / kujdes / problem / s'është provuar / joaktiv), përpjekja e fundit, suksesi i fundit (data e të dhënave; kurse të lexuara/ruajtura/pandryshuara), orari dhe veprimet **Provo tani** (lexon pa ruajtur, tregon çfarë dhe krahasimin me BSH-në), **Importo tani**, **Aktivizo/çaktivizo** dhe **Historia**.
- **Regjistri i përpjekjeve** (`fx_source_runs`, i pandryshueshëm): çdo përpjekje — nga orari automatik, nga përdoruesi ose nga komanda — ruhet me kohën, statusin (ok / pjesërisht / dështoi), numrat, kurset e hedhura dhe gabimin. Importet e BSH-së (komanda, butoni "Importo nga BSH tani") dhe të referencës regjistrohen njëlloj.
- **Adaptor i përgjithshëm JSON** (`JsonRateSource`): burim i ri = rresht te `fx_rate_sources` (URL, URL rezervë, mënyra `map` | `entries` | `list` | `per_pair`, orientimi X për 1 Lek ose Lek për 1 X, rruga e datës, token Bearer nga config). Burime të reja, të pa-aktivizuara parazgjedhur: **FloatRates**, **Coinbase (publik)**, **Wise** (kërkon `FX_WISE_TOKEN`), **Pasqyra e BSH-së (AllRatesToday)** (vetëm verifikim; kërkon atribuim të dukshëm).
- **Kontroll cilësie:** kursi i referencës/pasqyrës që largohet nga BSH më shumë se `FX_SOURCE_OUTLIER_PERCENT` (parazgjedhje 15%) **hidhet** dhe raportohet; nëse hidhen të gjitha, përpjekja dështon.
- Burimet e ndërtuara në kod (BSH/HTML, ExchangeRate-API, BQE, Currency-API) mbeten të pandryshuara; shfaqen në të njëjtën faqe. Kolonat e krahasimit te "Referenca" dhe "Publiko kurset" përfshijnë tani çdo burim të aktivizuar.

### U ndryshua
- `fx:import-reference` përdor regjistrin (burimet e ndërtuara të aktivizuara me `FX_REFERENCE_SOURCES` + burimet JSON të aktivizuara nga paneli), shfaq "(+x% nga BSH)" dhe regjistron çdo përpjekje. Importi i referencës planifikohet gjithmonë; komanda vendos vetë cilat burime janë aktive.
- Testet Feature ekzekutojnë `migrate` një herë për proces, që migrimet e reja të plugin-it të zbatohen edhe te tabelat `fxtest_*` ekzistuese.

### Teknike
- Migrim i ri `2026_10_12_000009_create_fx_sources_tables` (tabelat `fx_rate_sources`, `fx_source_runs` + 4 burime të pa-aktivizuara). Kod i ri: `SourceRegistry`, `SourceSyncService`, `Sources\JsonRateSource`, `FxSourceController`, modelet `FxRateSource`, `FxSourceRun`. Leje të reja: `fx.sources.index`, `fx.sources.manage` (thellësi 4).
- Konfigurim: `FX_WISE_TOKEN` (VETËM te `.env`), `FX_SOURCE_OUTLIER_PERCENT`, `FX_SOURCE_RETRY_SLEEP_MS`.
- Teste të reja: `SourceSyncTest` (adaptori për çdo mënyrë, regjistri i përpjekjeve, token Bearer, dështim pjesor, token që mungon, kurse të hedhura, fallback, importi i BSH-së, shëndeti). Gjithsej 146 teste.

### Kujdes pas instalimit
- Ekzekuto migrimet (`install.sh`). Burimet e reja janë të pa-aktivizuara: aktivizoji te FX · Kurset → Burimet (leja `fx.sources.manage`). Për Wise vendos `FX_WISE_TOKEN=...` te `.env` (pastaj `php artisan optimize:clear`) — token-i nuk shfaqet askund në panel.
- Rolet duhet të marrin lejet e reja (`fx.sources.index` për shikim, `fx.sources.manage` për importim/aktivizim).

## [0.8.0] — 2026-10-09 — "Kryqëzimi valutë↔valutë në sportel (dy këmbisa të lidhura, një kupon)"

### E shtuar
- **Sporteli: "Në këmbim të"** (Lek si parazgjedhje + butona për çdo monedhë tjetër aktive). Zgjedhja e një monedhe tjetër e kthen veprimin në **valutë↔valutë**: "Blejmë X" = klienti jep X dhe merr monedhën e zgjedhur; "Shesim X" = klienti merr X dhe jep monedhën e zgjedhur. Parashikimi në ekran (kush jep/merr sa, kursi i kryqëzuar, kusuri, kontrolli i gjendjes së arkës, pragu i identifikimit). **I fikur parazgjedhur**: `FX_CROSS_ENABLED=true` për ta ndezur.
- **Dy këmbisa të lidhura në regjistër, një veprim për klientin**: këmbisa 1 = zyra BLEN monedhën e dhënë (kurs blerje), këmbisa 2 = zyra SHET monedhën e marrë (kurs shitje), me të njëjtën vlerë në Lek — saktësisht siç e bënte arkëtari me dy veprime në Lek, por me një ekran, **një kupon** (numri i këmbisës 1; pa shfaqur dy veprime) dhe një rresht te lista e sportelit.
- **Llogaritja** (`FxCrossService`): pa rrumbullakim në shuma të plota; maksimumi 2 shifra pas pikës. "Klienti jep a": `L1 = a × blerja_A`, `b = poshtë(L1 ÷ shitja_B, 2)`; **kusuri** = diferenca e mbetur, **në Lek** (parazgjedhje; Leku neto i arkës = −kusuri) ose **në monedhën e dhënë** (`change_in=given`). "Klienti dëshiron b": `L2 = b × shitja_B`, `a = lart(L2 ÷ blerja_A, 2)`; pa kusur. Mbetja nën 1 Lek mbetet te zyra.
- **Anulimi** i një kryqëzimi anulon të dyja këmbisat (një arsye, një transaksion DB), nga cilado këmbisë; kontrollohet gjendja neto e arkës për secilën monedhë. Anulimet shfaqen si një rresht.
- **Raportimi i BSH-së** pa ndryshim: të dyja këmbisat dalin te F1.1 (blerja e A) dhe F1.2 (shitja e B) me të njëjtat Lek — praktika e deritanishme (`FX_CROSS_REPORT_MODE=lek_legs`). Formularët F2.1/F2.2 të shabllonit të BSH-së (spot valutë-valutë) mbeten opsion i ardhshëm (`f2`, ende i paimplementuar).
- **AIF**: vlera në Lek e kryqëzimit numërohet **një herë** (këmbisa 2 s'numërohet te `customerLekSince` dhe te lista AIF). Pragu i identifikimit (> 100 000 Lek) zbatohet mbi vlerën në Lek të kryqëzimit.
- Paneli i ditës e numëron një kryqëzim si **një** veprim; volumet për monedhë përfshijnë të dyja këmbisat. Flamuri `cross` (etiketë "Kryqëzim") te të dyja këmbisat.

### Teknike
- **Migrim i ri `2026_10_09_000008_add_cross_legs_to_fx_transactions`**: kolona opsionale `leg`, `pair_transaction_id` (unik, vetëm te këmbisa 2 — këmbisa 1 s'përditësohet kurrë), `change_amount`, `change_currency`. Veprimet ekzistuese mbeten të pandryshuara dhe të verifikueshme: hash-i i përfshin fushat e reja **vetëm kur ekzistojnë**.
- Kod i ri: `FxCrossService`; `FxMoney::floor/ceil`; `FxTransaction::supportsCross/notSecondLeg/pairLeg/firstLeg`; `FxCounterService::void` i përgjithësuar për grupe këmbisash; `record`, `resolveCustomer`, `customerLekSince`, `customerEurToday` u bënë publike.
- Teste të reja: `CounterCrossTest` (kursa, kusur në Lek dhe në monedhën e dhënë, shuma fikse me lart, anulimi i grupit, hash-i, validimet, çelësi i fikur) + `FxMoney::floor/ceil` + hash-i me fushat e reja. Gjithsej 130 teste.

### Kujdes pas instalimit
- Ekzekuto migrimet (`install.sh` e bën). Kryqëzimi mbetet i fikur derisa të vendosësh `FX_CROSS_ENABLED=true` te `.env` (pastaj `php artisan optimize:clear`). Ekzekuto testet (`tests/README.md`) para ndezjes.

## [0.7.6] — 2026-10-09 — "Migrimi i fx_sequences (gabimi 1553) dhe teste pa përdorues/bazë të re"

### U rregullua
- **Migrim i ri `2026_10_01_000001_prepare_fx_sequences_series`**: e kryen ndërrimin e indeksit unik te `fx_sequences` në rendin e duhur (kolona `series` → indeksi i ri `(office_id, year, series)` → hiqet `(office_id, year)`). Kështu migrimi `2026_10_02_000007` (i pandryshuar) e sheh kolonën `series` dhe e kalon bllokun e vet, dhe MySQL s'jep më error 1553 (`needed in a foreign key constraint`) në bazë të re. Idempotent: në një bazë ku ndërrimi është bërë (me dorë) s'bën asgjë; vetëm MySQL/MariaDB.

### U ndryshua (testet)
- Testet Feature s'kërkojnë më përdorues, bazë apo `.env.testing` të ri: përdorin të njëjtën lidhje me **prefiks tabelash `fxtest_`**, krijojnë vetë tabelat e testit (migrimet e plugin-it) herën e parë dhe kthejnë çdo test mbrapsht. Tabelat e prodhimit s'preken; mbrojtje: prefiksi duhet të përmbajë `test` dhe `APP_ENV=testing`. Shih `tests/README.md`.

## [0.7.5] — 2026-10-09 — "Rregullim: phpunit.xml.dist s'lexohej"

### U rregullua
- `phpunit.xml.dist` e 0.7.4 kishte `--testsuite` brenda një komenti XML (dyshja e vizave s'lejohet në koment), ndaj PHPUnit dilte me `Comment must not contain '--'` dhe s'ekzekutonte asnjë test. Komenti u pastrua; asgjë tjetër s'ndryshoi.

## [0.7.4] — 2026-10-09 — "Testet automatike (faza 1)"

Asnjë ndryshim në kodin e funksionimit; shtohen vetëm `tests/` dhe `phpunit.xml.dist` (shih `tests/README.md`).

### E shtuar
- **Suita Unit** (pa DB): `FxMoney` (rrumbullakimi, Lek për valutë me njësi, normalizimi, bcmath), hash-i i zinxhirit (çdo fushë kanonike e ndryshon hash-in, `customer_id` vetëm kur ekziston), dhe **`config/permissions.php`** (flamuj unikë, prindër para fëmijëve, **thellësia ≤ 4** — mbron nga gabimi 500 te System → Roles, çdo leje e përdorur ekziston, asnjë leje e vdekur, paneli kontrollon lejet e veta).
- **Suita Feature** (kërkon bazë testi me "test" në emër; s'prek prodhimin): sporteli (blerje/shitje, njësia 100, pragu 100 000 Lek, kufiri i gjendjes, refuzimet, kursi i vjetër, numrat pa boshllëqe), **anulimi** (rregullat e ditës/seancës/arsyes/gjendjes), **arka** (hapje, mbyllje, diferenca me arsye, miratimi, furnizim/derdhje), **zinxhiri hash** (ndryshimi jashtë aplikacionit zbulohet; boshllëku; prev_hash; pandryshueshmëria në ORM), **partnerët** (shërbime IN/OUT, gjendja e parapaguar, llogaria me partnerin, anulimi, zinxhiri "P"), **banka** (lëvizjet, lidhja me arkën, konfirmimi, kundërveprimi, efekti te partneri), **fitimi/humbja**, **kalendari i festave**.
- Mbrojtje: `FxTestCase` refuzon çdo bazë që s'përmban "test" dhe çdo mjedis ≠ `testing`; çdo test kthehet mbrapsht (DatabaseTransactions).
- Gjithsej **111 teste**. Dy janë me qëllim "njohuri e dokumentuar": flags s'hyn në hash (incomplete) dhe fushat jashtë listës kanonike (do të kthehen me hash-in e zgjeruar).

### Ende pa mbulim (vijon)
Raportet e BSH/AIF, klientët/AML/dokumentet, importi i kurseve (BSH, referenca), alarmet me email, testet HTTP të lejeve për çdo route.

## [0.7.3] — 2026-10-08 — "Rregullim: faqja e roleve (System → Roles) jepte 500"

### U rregullua
- **System → Roles → Edit jepte `Undefined variable $grandChildrenKey`** (`platform/core/acl/resources/views/roles/permissions.blade.php:98`). Shkaku: pema e lejeve e 0.7.1/0.7.2 kishte një zinxhir me 5 nivele (`fx` → grupi → `fx.rates.index` → `fx.rates.publish` → `fx.rates.override`), dhe pamja e Botble-s ka gabim në nivelin e pestë. `fx.rates.override` tani varet drejtpërdrejt nga `fx.rates.index`, ndaj thellësia maksimale është 4 nivele (si në 0.7.0). Flamujt s'ndryshojnë: asnjë rol s'preket.
- Pa migrim, pa ndryshim tjetër nga 0.7.2.

### Kujdes
- Pas instalimit: `php artisan config:clear` dhe `php artisan optimize:clear`.

## [0.7.2] — 2026-10-08 — "Bashkimi i 0.6.1 dhe 0.6.2 me 0.7.x: kursi i vjetër, arkat e hapura, burimet e referencës"

0.7.0 u ndërtua mbi 0.6.0, ndaj ndryshimet e 0.6.1 dhe 0.6.2 mungonin te 0.7.0 dhe 0.7.1. Tani janë të gjitha në një version. Përfshin gjithçka të 0.7.1 (lejet e panelit, lejet e organizuara sipas menysë).

### E shtuar (nga 0.6.1)
- **Paralajmërim për kursin e pa përditësuar:** sporteli tregon banner (pa bllokuar) kur kursi në fuqi i një monedhe është publikuar para ditës së sotme. Veprimi me kurs të tillë merr flamurin `stale_rate` ("Kurs i pa përditësuar"); flamujt s'hyjnë në hash. `.env`: `FX_RATE_STALE_WARNING=false` e fik (parazgjedhje: aktiv).
- **Burim i ri i referencës së tregut `fawaz`** (Currency-API përmes jsDelivr, pa çelës, ka ALL): `FX_REFERENCE_SOURCES=erapi,ecb,fawaz`. Opsional, s'aktivizohet vetë. Provë: `php artisan fx:import-reference --source=fawaz --dry-run`.

### E shtuar (nga 0.6.2)
- **FX · Sporteli → Arkat e hapura** (`fx.sessions.live`, leja `fx.sessions.index`): gjendja aktuale e çdo arke me seancë të hapur, për monedhë, me totalin dhe vlerën në Lek (vetëm lexim). Faqja e seancës së hapur tregon "Gjendja aktuale e arkës".
- **Referenca e tregut → "Nga vijnë këto kurse (burimet)":** emri, aktiv/jo, si llogaritet, URL dhe rreshti i fundit i ruajtur për çdo burim. Vetëm lexim; ndryshimi mbetet te `.env` (redaktimi nga paneli është planifikuar).

### Pa migrim të ri
- Nuk ka skedarë migrimi të rinj. Migrimi i 0.7.0 (`2026_10_02_000007`) mbetet i pandryshuar.

## [0.7.1] — 2026-10-08 — "Lejet e panelit: arkëtari s'sheh arkat dhe financat e të tjerëve"

### U rregullua
- **Paneli i ditës** shfaqte arkat e operatorëve të tjerë (dhe fitimin, bankën, partnerët) kujtdo me `fx.dashboard.index`. Tani çdo bllok kërkon lejen e vet (fail-closed, kontrolli në controller dhe në pamje):
  - arkat e të gjithëve + kufiri i gjendjes → `fx.sessions.index` (pa të: vetëm arka ime, pa lidhje te seanca);
  - fitimi/humbja, spread-i (kutia, kolona, shënimi) → **leja e re `fx.pnl.index`**;
  - likuiditeti (arkat + banka) dhe gjendjet e partnerëve → `fx.bank.index`.
- Llogaritjet e fitimit, bankës dhe partnerëve nuk ekzekutohen fare pa lejen përkatëse.

### U ndryshua
- **Lejet te System → Roles** janë të organizuara sipas menysë: FX · Sporteli, Kurset, Financat, Compliance, Konfigurimi, me emra "Grupi · Veprimi". Flamujt e veprimeve s'ndryshojnë (rolet ekzistuese s'prishen); shtohen vetëm 5 prindër vizualë `fx.group.*` që s'autorizojnë asgjë.

### Kujdes pas instalimit
- Roli që e shihte fitimin/bankën/arkat te paneli duhet të marrë lejet e reja/përkatëse te System → Roles (`fx.pnl.index`, `fx.sessions.index`, `fx.bank.index`); superadmini s'preket. Arkëtari me vetëm `fx.counter.use` përdor "Paneli im".
- Pa migrim.

## [0.7.0] — 2026-10-02 — "Partnerët në sportel, banka, qarku i mbyllur, fitim/humbja reale dhe paneli personal"

### E shtuar
- **Shërbimet e partnerëve** (AK-Invest etj.) në të njëjtën arkë: MG tërheqje (OUT), MG dërgesë, gjoba, drita, ujësjellës, internet, tatime (IN). Një vlerë e vetme për veprim (komisioni brenda); numër `Z1-P-2026-000001` pa boshllëqe, zinxhir hash (verifikohet nga `fx:verify-ledger`), anulim vetëm të njëjtën ditë nga supervizori. Hyjnë në rakordimin e arkës dhe në kufirin 5M; s'hyjnë në raportin e BSH-së.
- **Libri i partnerit**: *gjendje e parapaguar* (pagesat utilitare e zbresin; mbushet me transfertë nga banka; veprimi refuzohet kur s'mjafton; email kur bie nën pragun) dhe *llogari me partnerin* (tërheqjet MG → partneri na detyrohet; rimbursimi ditën e ardhshme të punës; email te kujtesa e mëngjesit kur vonon). Mënyra e mbylljes zgjidhet për çdo shërbim.
- **Banka**: llogaritë dhe lëvizjet me dorë (derdhje/tërheqje cash e lidhur me lëvizjen e arkës me shumën e saktë, mbushje te partneri, rimbursim, pagesë, të tjera me shënim); konfirmim manual kundrejt ekstraktit; gabimet me kundërveprim (s'fshihet asgjë).
- **Qarku i mbyllur**: derdhje/furnizime arke pa bankë, lëvizje bankare pa konfirmim, detyrime partneri të parimbursuara me vjetërsinë (FIFO), gjendje të parapaguara të ulëta.
- **Raporti ditor i partnerëve** (hyrje/dalje për shërbim e monedhë, gjendjet në fillim e në fund, lista e veprimeve) + CSV.
- **Fitimi/humbja reale** te Paneli i ditës: kosto mesatare e ponderuar për monedhë, fitim i realizuar (dita/muaji), diferencat e arkës, e parealizuara me kursin BSH; likuiditeti arka + banka; gjendjet e partnerëve.
- **Paneli im** (çdo arkëtar): arka ime, këmbimet dhe shërbimet e mia sot, diferencat e mia në mbyllje (30 ditë).
- Menyja: grupi i ri **FX · Financat** (Paneli i ditës, Qarku, Banka, Raporti i partnerëve); "Paneli im" te FX · Sporteli; "Partnerët" te FX · Konfigurimi. Lejet: `fx.partners.manage`, `fx.bank.index`, `fx.bank.manage`, `fx.bank.confirm`.
- AK-Invest dhe shërbimet krijohen gati (të editueshme).

### U ndryshua
- `fx_sequences.series` (FX / P): seri numërimi më vete për veprimet e partnerëve.
- `fx:purge-test-data` fshin edhe veprimet e partnerëve, librin e partnerit dhe lëvizjet bankare (llogaritë dhe partnerët mbeten).

 — 2026-09-27 — "Paneli i ditës, festat zyrtare, kopjet e dokumenteve, eksporti mujor dhe pastrimi i provës"

### Përfshin
- Rregullimet e 0.5.3 dhe 0.5.4 (F3 numerik, "te ndryshem" te rreshtat e agreguar).

### E shtuar
- **Paneli i ditës** (FX · Sporteli → Paneli, leja `fx.dashboard.index`): veprimet, klientët e identifikuar, sinjalet AIF, volumi dhe spread-i i vlerësuar për monedhë (kundrejt kursit BSH të ruajtur te veprimi — jo fitim kontabël), kufiri i gjendjes, gjendja e çdo arke, raportet e BSH-së pa dorëzuar me afatin, rastet AML të hapura.
- **Festat zyrtare** (`fx_holidays`, FX · Konfigurimi → Festat, leja `fx.holidays.manage`): festat e fiksuara dhe Pashkët (katolike e ortodokse) llogariten vetë; festa e dielë → pushim të hënën; Bajramet dhe ditët e shpallura shtohen nga paneli. Përdoren nga afati i raportit të BSH-së, kujtesat dhe importi i BSH-së (s'ka njoftim "s'u publikua" në festë).
- **Kopjet e dokumentit të identifikimit** (`fx_customer_documents`): ngarkim nga kartela e klientit (edhe me kamerën e telefonit), skedar i enkriptuar me FX_DATA_KEY jashtë dosjes publike, i pandryshueshëm, me checksum; çdo ngarkim/shikim në `fx_access_logs`. `fx:rotate-key` i rienkripton.
- **Eksporti mujor për kontabilistin** (Raportet → CSV): të gjitha veprimet e muajit me statusin (i vlefshëm / i anuluar / anulim) dhe përmbledhja për monedhë; pa të dhëna personale; s'ruhet në server; shkarkimi regjistrohet.
- `php artisan fx:purge-test-data --confirm=KODI [--with-rates]`: fshin të dhënat e provës para vënies në punë (refuzon nëse ka raporte të dorëzuara; kërkon "FSHI"; një transaksion DB).

 — 2026-09-27 — "Menyja në katër grupe sipas rolit"

### U ndryshua
- Menyja e Emiron FX ndahet në **FX · Sporteli** (Sporteli, Seancat, Regjistri), **FX · Kurset** (Publiko, Historia, Kursi BSH, Referenca, Monedhat), **FX · Compliance** (Raportet, Sinjalizime AIF, Klientët, AML) dhe **FX · Konfigurimi** (Zyrat, Arkat). Çdo grup shfaqet vetëm nëse përdoruesi ka qoftë një leje brenda tij (RULE_043). Route-t dhe lejet s'ndryshojnë.

 — 2026-09-27 — "Çelës i veçantë për të dhënat personale, i pavarur nga APP_KEY"

### E shtuar
- `FX_DATA_KEY` (`Support/FxCrypto`, cast `FxEncrypted`): të dhënat personale të klientëve, rastet AML dhe referencat e identifikimit enkriptohen me çelësin e Emiron FX, jo me APP_KEY. Pa FX_DATA_KEY përdoret APP_KEY (të dhënat e 0.5.0 lexohen pa ndryshim).
- `php artisan fx:rotate-key [--new=] [--dry-run]`: kontrollon që çdo vlerë dekriptohet, rienkripton brenda një transaksioni DB, rillogarit kërkimin me dokument (`doc_hash`), shkruan `.env` me backup (FX_DATA_KEY i ri, i vjetri te FX_DATA_PREVIOUS_KEYS vetëm për lexim) dhe kthen gjithçka mbrapa nëse diçka dështon.
- `FX_DATA_PREVIOUS_KEYS`: çelësa të vjetër vetëm për lexim (p.sh. backup i para rrotullimit).

### Siguri
- Lista AIF ruhet e enkriptuar në disk (`.csv.enc`) dhe dekriptohet vetëm në shkarkim (me gjurmë). Listat e 0.5.0 enkriptohen gjatë `fx:rotate-key`.
- Ndërrimi i APP_KEY s'prek më të dhënat e klientëve pasi FX_DATA_KEY është vendosur.

 — 2026-09-27 — "Fazat 2b/2c/2d: klientët, identifikimi > 100 000 Lek, AML, raportet e BSH-së dhe AIF-it"

### U ndryshua
- Identifikimi i detyrueshëm: veprimi **> 100 000 Lek** (`FX_ID_THRESHOLD_LEK`) s'ruhet pa klient të identifikuar me dokument të vlefshëm. Zëvendëson konfirmimin me tekst të lirë të 0.4.0 (pragu 1 000 000 mbetet vetëm për sinjalin AIF).
- Sinjali AIF (`aif_cash`) llogaritet për klientin mbi 24 orët e fundit (veprime të lidhura), jo vetëm për veprimin e vetëm.
- Zinxhiri hash përfshin `customer_id` kur ekziston (veprimet e 0.4.0 mbeten të verifikueshme).

### E shtuar
- **Klientët** (`fx_customers`): emri, dokumenti, datëlindja, adresa, telefoni të enkriptuar; kërkimi me dokument me HMAC ("blind index"); PEP, niveli i riskut, pezullimi. Sporteli: kërkim me dokument, klient i ri në të njëjtën formë; kategoria e BSH-së merret nga klienti.
- Klient i pezulluar → refuzim; dokument i skaduar → refuzim; PEP → vetëm me lejen `fx.counter.pep`.
- **AML** (`fx_aml_cases`): "Shëno veprim të dyshimtë" nga sporteli pezullon klientin; lista dhe shqyrtimi vetëm për `fx.aml.manage` (status, referenca e RAD-it te AIF, heqja e pezullimit).
- **Raportet** (`fx_reports`): libri "Statistika Monetare Ditore" i BSH-së i plotësuar direkt në shabllonin zyrtar (Cover Page B9, F1.1, F1.2, F3 nga rreshti 9; stilet dhe listat ruhen) — Individual ≥ 50 000 EUR për klient/ditë/drejtim (emri vetëm për jo-individët), I agreguar për kategori × monedhë, kurs mesatar i ponderuar; lista AIF (CSV) për klientët me cash ≥ 1 000 000 Lek në 24 orë. Versione me checksum SHA-256, shkarkim me verifikim, shënim i dorëzimit me referencë. Raporti gjenerohet vetëm pasi arkat e ditës janë mbyllur.
- **Kujtesat** `fx:report-reminders` (ditë pune 09:30; të hënën kontrollon edhe fundjavën) dhe **verifikimi i natës** `fx:verify-ledger --notify` (02:30), me email.
- **Gjurmët e aksesit** (`fx_access_logs`): kërkim, shikim, krijim, ndryshim, pezullim klienti; hapje/shqyrtim AML; gjenerim/shkarkim raporti.
- Kuponi shfaq klientin (dokumenti i maskuar).
- Menyja: Raportet, Klientët, Raste të dyshimta (AML). Lejet: `fx.counter.pep`, `fx.customers.*`, `fx.aml.manage`, `fx.reports.*`.

 — 2026-09-27 — "Faza 2a: sporteli, arkat dhe regjistri i pandryshueshëm"

### E shtuar
- Zyrat (`fx_offices`, me licencën, NIPT-in dhe kodin e kuponit) dhe arkat (`fx_tills`): një arkëtar = një arkë.
- Seancat e arkës (`fx_till_sessions`): hapje me numërim, mbyllje me rakordim (numëruar ↔ libri), miratim nga supervizori; arka s'rihapet pa miratimin e mbylljes së mëparshme.
- Libri i arkës (`fx_till_movements`, i pandryshueshëm): gjendja = shuma e lëvizjeve; diferencat në hapje/mbyllje janë lëvizje me arsye të detyrueshme; furnizim/derdhje me arsye (leja `fx.counter.movement`).
- Regjistri i veprimeve (`fx_transactions`, i pandryshueshëm): numër pa boshllëqe për zyrë/vit (`fx_sequences`, kyçje rreshti), zinxhir hash SHA-256, snapshot i kursit të aplikuar, kursit BSH dhe ekuivalentit në EUR.
- Sporteli: kursi merret nga publikimi në fuqi (arkëtari s'e shkruan), pa komision, Lek të plotë; kontrolli i gjendjes së arkës; kuponi 80 mm me printim automatik.
- Kufiri i gjendjes së subjektit < 5 000 000 Lek (të gjitha arkat e monedhat, me kursin BSH): veprimi/furnizimi që e kalon refuzohet; paralajmërim nga 90%.
- ≥ 1 000 000 Lek: konfirmim identifikimi + referencë dokumenti (deri në Fazën 2b), flamujt `cdd` dhe `aif_cash`; faqja "Sinjalizime AIF". ≥ 50 000 EUR: flamuri `boa_individual` për raportin e BSH-së.
- Anulimi: vetëm i njëjti ditë, para mbylljes, nga supervizori, me arsye — veprim i kundërt me numër të ri; origjinali mbetet.
- Referenca e identifikimit (`cdd_reference`) ruhet e enkriptuar (cast `encrypted`, APP_KEY) dhe s'hyn në hash.
- `php artisan fx:verify-ledger` — verifikon numërimin dhe zinxhirin hash.
- Menyja: Sporteli, Regjistri i veprimeve, Sinjalizime AIF, Seancat e arkave, Arkat, Zyrat. Lejet e reja `fx.counter.*`, `fx.sessions.*`, `fx.transactions.index`, `fx.offices.manage`.

 — 2026-09-26 — "Referenca e tregut ka vendin e vet në panel"

### E shtuar
- Menyja → Këmbimi valutor → **Referenca e tregut** (RULE_043): tabela krahasuese BSH ↔ çdo burim me shmangien në %, butoni "Importo referencën tani" dhe "Historia e referencës".
- Route-t `fx.reference.index|history|import`; lejet `fx.reference.index`, `fx.reference.import`.

### U rregullua
- 0.3.0 e kishte referencën vetëm si komandë dhe si kolonë te "Publiko kurset", pa zë menyje dhe pa mënyrë për ta thirrur nga paneli.

 — 2026-09-26 — "Referenca e tregut dhe shigjetat e ndryshimit"

### E shtuar
- Referenca e tregut (JO kurs zyrtar), tabela `fx_reference_rates`, komanda `fx:import-reference [--source=erapi|ecb] [--dry-run]`, ditë pune 12:25 dhe 16:40:
  - `erapi` — ExchangeRate-API open access (ka ALL; Lek për X = 1 ÷ kursi);
  - `ecb` — BQE përmes Frankfurter (s'ka ALL; Lek për X = EUR i BSH-së ÷ kursi BQE X/EUR).
- "Publiko kurset": kolona "Referenca e tregut" me shmangien e secilit burim nga BSH (e kuqe mbi `FX_REFERENCE_DIVERGENCE_ALERT_PERCENT`, 1.5%).
- Njoftimet: rreshti "tregu: …" për çdo monedhë; arsye e re kur BSH largohet nga referenca mbi prag; email-i "BSH pa kurs sot" përfshin referencën e tregut.
- Shigjetat ▲▼ (u rrit / u ul, me vlerën e mëparshme në titull dhe për lexuesit e ekranit): te tabela publike për blerjen, shitjen dhe kursin BSH, dhe te "Publiko kurset" për kursin në fuqi.

### Parimi
- Kontrolli i shmangies dhe sugjerimi mbeten VETËM kundrejt kursit zyrtar të BSH-së; referenca s'publikon e s'ndryshon asgjë.

 — 2026-09-26 — "Importi nga BSH s'raporton më sukses kur s'ka ruajtur asgjë"

### U rregullua
- Data e publikimit s'gjendej në HTML-në reale të BSH-së (etiketat/`&nbsp;` rreth "Last update"), ndaj asnjë kurs s'ruhej. Tani data merret si data e fundit `dd.mm.yyyy [hh:mm:ss]` në tekstin para çdo tabele, pavarësisht etiketave dhe gjuhës së faqes.
- Tabelë me kurse por pa datë → përjashtim ("faqja mund të ketë ndryshuar"), jo rezultat bosh.
- Importi që s'përputh asnjë monedhë të zyrës raportohet si dështim (butoni: mesazh gabimi me kurset e lexuara; komanda: FAILURE dhe, te `--final`, njoftim me email). Më parë dilte "sukses: 0, 0, 0".

### E shtuar
- `fx:import-official --save-html=/tmp/boa.html` ruan faqen e BSH-së për diagnostikim.

 — 2026-09-26 — "Kursi zyrtar i BSH-së automatikisht dhe njoftimet kur kurset duhen rregulluar"

### E shtuar
- Importi i kursit zyrtar nga faqja publike e BSH-së (`BoaRateFetcher`): merr vetëm tabelat e kursit zyrtar (anashkalon Bid/Ask), datën "Last update" për çdo tabelë dhe njësinë nga emri (JPY 100). Pa kurse → përjashtim, jo "bosh = ok".
- Komanda `fx:import-official [--dry-run] [--final]`, e planifikuar ditë pune 12:20, 13:00 dhe 13:30 (përpjekja e fundit), ora e Tiranës; e fikshme me `FX_BOA_IMPORT_ENABLED=false`.
- Butoni "Importo nga BSH tani" te Kursi zyrtar (route `fx.official-rates.import`, leja `fx.official-rates.create`).
- Njoftimet me email (motori EmailHandler i Botble-s, shablloni `fx-rate-alert`, editohet te Settings → Email): shmangie mbi prag pas kursit të ri të BSH-së, lëvizje ditore e BSH-së mbi `FX_OFFICIAL_MOVE_ALERT_PERCENT` (1%), import i dështuar, BSH pa kurs për sot. Marrësit: `FX_ALERT_EMAILS`.
- Tabela `fx_alerts`: çdo njoftim (lloj + ditë + gjurmë) dërgohet një herë; ruhen marrësit, koha e dërgimit dhe gabimi.
- Sugjerimi i kursit të ri që ruan marzhet e publikimit të fundit kundrejt BSH-së: në email dhe te "Publiko kurset" (klik → mbush fushat). S'publikohet kurrë automatikisht.

### U ndryshua
- Tabela publike tregon qartë se kurset janë kundrejt Lekut: "1 EUR … Lek", "Blejmë — kur ju shisni valutë", "Shesim — kur ju blini valutë".

### U rregullua
- Tabelat admin (historia, kursi zyrtar, monedhat) s'mbusheshin: Botble i kërkon të dhënat me POST; route-t `*.index` pranojnë tani GET dhe POST.

## [0.1.0] — 2026-09-26 — "Faza 1: kurset e zyrës, kursi zyrtar i BSH-së dhe tabela publike"

### E shtuar
- Plugin i ri `emiron-fx`, i pavarur nga RadiusManager.
- Tabelat `fx_currencies`, `fx_rates`, `fx_official_rates` (migrimi `2026_09_26_000001`); EUR/USD/GBP/CHF të krijuara joaktive.
- Publikimi i kurseve blerje/shitje në grup (`batch_uuid`), me kontrollin blerje < shitje dhe shmangien nga kursi zyrtar i BSH-së; mbi pragun kërkohet `fx.rates.override` + arsyeja.
- Regjistrimi manual i kursit zyrtar të BSH-së për datë; korrigjimi si rresht i ri.
- Tabelat admin: historia e kurseve, kurset zyrtare, monedhat.
- Shortcode `[fx-rates]`: tabela publike blerje/shitje (+ kursi BSH si referencë) dhe konvertuesi.
- Përkthimet sq/en (RULE_027), lejet te `config/permissions.php` (RULE_026), menyja (RULE_043).

### Siguri
- `fx_rates` dhe `fx_official_rates` të pandryshueshme në kod (trait `Immutable`, parimi i RULE_051).
- Heqja e plugin-it nuk fshin të dhënat.
- Çdo publikim lë gjurmë te audit-log i Botble-s (`CreatedContentEvent`, RULE_031).
