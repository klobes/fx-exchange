<?php

return [
    /*
     * Shmangia maksimale (në %) e kursit të blerjes/shitjes nga kursi zyrtar
     * i BSH-së, pa kërkuar konfirmim. Mbi këtë vlerë publikimi refuzohet,
     * përveç kur përdoruesi ka lejen fx.rates.override dhe shkruan arsyen.
     * E njëjta vlerë nxit njoftimin me email pas importit të kursit të BSH-së.
     */
    'deviation_threshold_percent' => (float) env('FX_DEVIATION_THRESHOLD_PERCENT', 3),

    /*
     * Lëvizja ditore e kursit zyrtar (në %) që nxit njoftim, edhe kur kurset
     * e zyrës janë ende brenda pragut të shmangies.
     */
    'official_move_alert_percent' => (float) env('FX_OFFICIAL_MOVE_ALERT_PERCENT', 1),

    // Shkalla e bcmath për llogaritjet e ndërmjetme.
    'bc_scale' => 10,

    // Shifrat dhjetore të kurseve (Lek për njësi valute).
    'rate_decimals' => 4,

    /*
     * Importi automatik i kursit zyrtar nga faqja publike e BSH-së.
     * Orari (ditë pune, ora e Tiranës): BSH e llogarit 11:30–12:00.
     * Përpjekja e fundit (final) dërgon njoftim nëse kursi i ditës s'u mor.
     */
    'boa_import_enabled' => (bool) env('FX_BOA_IMPORT_ENABLED', true),
    'boa_url' => env('FX_BOA_URL', 'https://www.bankofalbania.org/Markets/Official_exchange_rate/'),
    'boa_import_times' => ['12:20', '13:00'],
    'boa_import_final_time' => '13:30',

    /*
     * Marrësit e njoftimeve (presje mes tyre), p.sh. FX_ALERT_EMAILS="a@x.al,b@x.al".
     * Bosh = s'dërgohet email; njoftimi mbetet vetëm te paneli dhe log.
     */
    /*
     * Referenca e tregut (JO kurs zyrtar): krahasim me BSH-në në panel dhe njoftime.
     *  erapi — ExchangeRate-API open access (ka ALL; ditor; atribuim nëse shfaqet publikisht)
     *  ecb   — BQE përmes Frankfurter (~16:00 CET; kryqëzim me EUR-in e BSH-së)
     * Njoftim kur kursi i BSH-së largohet nga referenca më shumë se pragu.
     */
    'reference_sources' => array_values(array_filter(array_map('trim', explode(',', (string) env('FX_REFERENCE_SOURCES', 'erapi,ecb'))))),
    'reference_erapi_url' => env('FX_REFERENCE_ERAPI_URL', 'https://open.er-api.com/v6/latest/ALL'),
    // 0.6.1: burim i tretë, pa çelës (aktivizohet me FX_REFERENCE_SOURCES=erapi,ecb,fawaz).
    'reference_fawaz_url' => env('FX_REFERENCE_FAWAZ_URL', 'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/all.json'),
    'reference_ecb_url' => env('FX_REFERENCE_ECB_URL', 'https://api.frankfurter.dev/v1/latest?base=EUR'),
    'reference_import_times' => ['12:25', '16:40'],
    'reference_divergence_alert_percent' => (float) env('FX_REFERENCE_DIVERGENCE_ALERT_PERCENT', 1.5),

    /*
     * Faza 2a — sporteli.
     *
     * holding_limit_lek: gjendja maksimale cash e SUBJEKTIT (të gjitha arkat,
     *   të gjitha monedhat, me kursin zyrtar të BSH-së), sipas kapitalit.
     *   Veprimi/furnizimi që e kalon bllokohet; mbi `holding_warn_percent` paralajmërim.
     * cdd_threshold_lek: identifikimi i klientit (ligji 9917) dhe sinjalizimi
     *   i veprimit cash për AIF-in (raportim ditën pasardhëse të punës).
     * boa_individual_eur: rresht individual në raportin e BSH-së (≥, udhëzimi nr. 3/2021).
     * lek_decimals: shumat cash në lekë rrumbullakosen (0 = lekë të plota).
     */
    'holding_limit_lek' => (string) env('FX_HOLDING_LIMIT_LEK', '5000000'),
    'holding_warn_percent' => (float) env('FX_HOLDING_WARN_PERCENT', 90),
    /*
     * Çelësi i të dhënave personale (FxCrypto), i ndarë nga APP_KEY. Bosh = APP_KEY
     * (përputhshmëri me 0.5.0). Ndërrohet VETËM me `php artisan fx:rotate-key`.
     * Lexohet këtu (jo me env() në kod) që të funksionojë edhe me config:cache.
     */
    'data_key' => (string) env('FX_DATA_KEY', ''),
    'data_previous_keys' => array_values(array_filter(array_map('trim', explode(',', (string) env('FX_DATA_PREVIOUS_KEYS', ''))))),

    // Identifikimi i detyrueshëm i klientit: veprimi > kjo shumë (Lek) s'ruhet pa klient të identifikuar.
    'id_threshold_lek' => (string) env('FX_ID_THRESHOLD_LEK', '100000'),
    // Sinjali për AIF-in: cash ≥ kjo shumë me të njëjtin klient brenda 24 orëve (ligji 9917 / udhëzimi AIF).
    'cdd_threshold_lek' => (string) env('FX_CDD_THRESHOLD_LEK', '1000000'),
    'boa_individual_eur' => (string) env('FX_BOA_INDIVIDUAL_EUR', '50000'),
    'lek_decimals' => (int) env('FX_LEK_DECIMALS', 0),

    /*
     * 0.8.0 — kryqëzimi valutë↔valutë në sportel (dy këmbisa të lidhura, një kupon).
     * cross_enabled: çelësi (parazgjedhje i fikur). cross_report_mode: lek_legs = të dyja këmbisat raportohen te F1.1 + F1.2
     * me të njëjtën vlerë në Lek (praktika e deritanishme); f2 = rezervë për formularët F2.1/F2.2 të BSH-së (ende i paimplementuar).
     */
    'cross_enabled' => (bool) env('FX_CROSS_ENABLED', true), // 0.8.3: aktiv parazgjedhur; FX_CROSS_ENABLED=false e fik

    /*
     * 0.8.4 — mbrojtja nga humbja: shitje me kurs nën koston mesatare të stokut.
     * off | warn (lejohet + flamur) | block (vetëm supervizor me leje fx.counter.below_cost + arsye). Mund të ndërrohet te Burimet.
     */
    'loss_guard_mode' => env('FX_LOSS_GUARD_MODE', 'block'),

    /*
     * 0.8.4 — marzhi i përgjithshëm i rekomandimit (Lek): blerja = MID − x, shitja = MID + x kur mënyra e zgjedhur s'ka bazë.
     * Mund të ndryshohet te Burimet (blerje dhe shitje veç e veç).
     */
    'market_default_margin_lek' => env('FX_MARKET_DEFAULT_MARGIN_LEK', '0.5'),

    /*
     * 0.8.1 — Qendra e burimeve.
     * source_outlier_percent: kurs i referencës/pasqyrës që largohet nga BSH më shumë se kaq (%) hidhet (s'ruhet).
     * Token-et e API-ve (p.sh. Wise) NUK janë këtu: ruhen te DB të enkriptuara (0.8.3), vendosen te Burimet.
     */
    'source_outlier_percent' => (float) env('FX_SOURCE_OUTLIER_PERCENT', 15),
    'source_retry_sleep_ms' => (int) env('FX_SOURCE_RETRY_SLEEP_MS', 1500),

    /*
     * 0.8.2 — kursi i rekomanduar nga tregu (mediana e burimeve "në mesatare"; parazgjedhur vetëm Wise).
     * market_margin_mode: last | percent | lek (zëvendësohet nga cilësimi te paneli).
     * market_drift_alert_percent: banner + email kur MID i tregut largohet nga mesi i kursit të publikuar mbi këtë prag.
     * market_max_age_days: burimi i vjetër se kaq nuk hyn në mesatare. market_outlier_percent: (≥ 3 burime) largësia nga mediana.
     */
    'market_margin_mode' => env('FX_MARKET_MARGIN_MODE', 'last'),
    'market_drift_alert_percent' => (float) env('FX_MARKET_DRIFT_ALERT_PERCENT', 0.5),
    'market_min_sources' => (int) env('FX_MARKET_MIN_SOURCES', 1),
    'market_max_age_days' => (int) env('FX_MARKET_MAX_AGE_DAYS', 2),
    'market_outlier_percent' => (float) env('FX_MARKET_OUTLIER_PERCENT', 1.0),
    'market_decimals' => (int) env('FX_MARKET_DECIMALS', 2),
    'cross_report_mode' => env('FX_CROSS_REPORT_MODE', 'lek_legs'),

    // 0.6.1: paralajmërim (jo bllokim) kur kursi në fuqi është publikuar para ditës së sotme.
    'rate_stale_warning' => (bool) env('FX_RATE_STALE_WARNING', true),

    // Dokumentet e identifikimit të pranuara në sportel.
    'id_document_types' => ['id_card', 'passport', 'residence_permit', 'other'],

    // Raportet: libri i BSH-së dhe afati (ora e ditës pasardhëse të punës).
    'boa_report_template' => 'report-templates/statistika_monetare_ditore.xlsx',
    'boa_report_deadline' => '12:00',
    'report_reminder_time' => '09:30',
    'reports_disk_path' => 'fx-reports',

    // Kategoritë e klientit — tekstet e sakta të listës së BSH-së (Formulari, "Data types").
    'client_categories' => [
        'Individë',
        'Biznes',
        'Kompani /institucion shteteror',
        'Bankë',
        'Zyrë këmbimi valutor',
        'Të tjera',
    ],

    'alert_emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('FX_ALERT_EMAILS', ''))))),
];
