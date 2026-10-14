<?php

/*
 * Emiron FX — permissions, të organizuara si menyja e plugin-it (0.7.1).
 *
 * Renditja dhe grupet ndjekin menynë: Sporteli → Kurset → Financat → Compliance → Konfigurimi.
 * `fx` dhe `fx.group.*` janë VETËM prindër vizualë në ekranin e roleve (asnjë route/controller
 * nuk autorizohet me ta; i njëjti parim si RULE_111/RULE_112). Flamujt e veprimeve s'ndryshojnë,
 * ndaj rolet ekzistuese vazhdojnë të funksionojnë; ndryshon vetëm renditja, emrat dhe prindi vizual.
 */
return [
    ['name' => 'Këmbimi valutor (FX)', 'flag' => 'fx'],

    // ───────────── FX · Sporteli ─────────────
    ['name' => 'FX · Sporteli (arkëtari dhe supervizori)', 'flag' => 'fx.group.counter', 'parent_flag' => 'fx'],
    ['name' => 'Sporteli · Paneli im dhe veprime në arkën time', 'flag' => 'fx.counter.use', 'parent_flag' => 'fx.group.counter'],
    ['name' => 'Sporteli · Anulo veprim (supervizor)', 'flag' => 'fx.counter.void', 'parent_flag' => 'fx.counter.use'],
    ['name' => 'Sporteli · Furnizim/derdhje arke (supervizor)', 'flag' => 'fx.counter.movement', 'parent_flag' => 'fx.counter.use'],
    ['name' => 'Sporteli · Veprim me klient PEP (supervizor)', 'flag' => 'fx.counter.pep', 'parent_flag' => 'fx.counter.use'],
    ['name' => 'Sporteli · Shitje nën kosto (supervizor)', 'flag' => 'fx.counter.below_cost', 'parent_flag' => 'fx.counter.use'],
    ['name' => 'Seancat · Shiko arkat dhe seancat e të gjithëve', 'flag' => 'fx.sessions.index', 'parent_flag' => 'fx.group.counter'],
    ['name' => 'Seancat · Mirato mbylljen e arkës', 'flag' => 'fx.sessions.approve', 'parent_flag' => 'fx.sessions.index'],
    ['name' => 'Regjistri · Shiko veprimet dhe sinjalizimet AIF', 'flag' => 'fx.transactions.index', 'parent_flag' => 'fx.group.counter'],

    // ───────────── FX · Kurset ─────────────
    ['name' => 'FX · Kurset', 'flag' => 'fx.group.rates', 'parent_flag' => 'fx'],
    ['name' => 'Kurset · Shiko publikimet dhe historinë', 'flag' => 'fx.rates.index', 'parent_flag' => 'fx.group.rates'],
    ['name' => 'Kurset · Publiko kurse të reja', 'flag' => 'fx.rates.publish', 'parent_flag' => 'fx.rates.index'],
    ['name' => 'Kurset · Publiko mbi pragun e shmangies', 'flag' => 'fx.rates.override', 'parent_flag' => 'fx.rates.index'],
    ['name' => 'Kursi BSH · Shiko', 'flag' => 'fx.official-rates.index', 'parent_flag' => 'fx.group.rates'],
    ['name' => 'Kursi BSH · Regjistro / importo', 'flag' => 'fx.official-rates.create', 'parent_flag' => 'fx.official-rates.index'],
    ['name' => 'Burimet e kurseve · Shiko statusin, historinë dhe "Provo tani"', 'flag' => 'fx.sources.index', 'parent_flag' => 'fx.group.rates'],
    ['name' => 'Burimet e kurseve · Importo tani dhe aktivizo/çaktivizo', 'flag' => 'fx.sources.manage', 'parent_flag' => 'fx.sources.index'],
    ['name' => 'Referenca e tregut · Shiko', 'flag' => 'fx.reference.index', 'parent_flag' => 'fx.group.rates'],
    ['name' => 'Referenca e tregut · Importo tani', 'flag' => 'fx.reference.import', 'parent_flag' => 'fx.reference.index'],
    ['name' => 'Monedhat · Shiko', 'flag' => 'fx.currencies.index', 'parent_flag' => 'fx.group.rates'],
    ['name' => 'Monedhat · Krijo', 'flag' => 'fx.currencies.create', 'parent_flag' => 'fx.currencies.index'],
    ['name' => 'Monedhat · Ndrysho', 'flag' => 'fx.currencies.edit', 'parent_flag' => 'fx.currencies.index'],

    // ───────────── FX · Financat ─────────────
    ['name' => 'FX · Financat (pronari/supervizori)', 'flag' => 'fx.group.finance', 'parent_flag' => 'fx'],
    ['name' => 'Paneli i ditës · Shiko (veprimet, sinjalet; arka ime)', 'flag' => 'fx.dashboard.index', 'parent_flag' => 'fx.group.finance'],
    ['name' => 'Paneli i ditës · Fitimi/humbja dhe spread-i', 'flag' => 'fx.pnl.index', 'parent_flag' => 'fx.dashboard.index'],
    ['name' => 'Banka dhe qarku · Shiko (edhe likuiditeti e partnerët te paneli)', 'flag' => 'fx.bank.index', 'parent_flag' => 'fx.group.finance'],
    ['name' => 'Banka · Regjistro lëvizje / llogari', 'flag' => 'fx.bank.manage', 'parent_flag' => 'fx.bank.index'],
    ['name' => 'Banka · Konfirmo kundrejt ekstraktit', 'flag' => 'fx.bank.confirm', 'parent_flag' => 'fx.bank.index'],

    // ───────────── FX · Compliance ─────────────
    ['name' => 'FX · Compliance', 'flag' => 'fx.group.compliance', 'parent_flag' => 'fx'],
    ['name' => 'Raportet · Shiko/shkarko (BSH, partnerët, eksporti mujor)', 'flag' => 'fx.reports.index', 'parent_flag' => 'fx.group.compliance'],
    ['name' => 'Raportet · Gjenero', 'flag' => 'fx.reports.generate', 'parent_flag' => 'fx.reports.index'],
    ['name' => 'Raportet · Shëno të dorëzuar', 'flag' => 'fx.reports.submit', 'parent_flag' => 'fx.reports.index'],
    ['name' => 'Klientët · Shiko', 'flag' => 'fx.customers.index', 'parent_flag' => 'fx.group.compliance'],
    ['name' => 'Klientët · Krijo/ndrysho', 'flag' => 'fx.customers.edit', 'parent_flag' => 'fx.customers.index'],
    ['name' => 'AML · Rastet e dyshimta (compliance)', 'flag' => 'fx.aml.manage', 'parent_flag' => 'fx.group.compliance'],

    // ───────────── FX · Konfigurimi ─────────────
    ['name' => 'FX · Konfigurimi (administratori)', 'flag' => 'fx.group.settings', 'parent_flag' => 'fx'],
    ['name' => 'Zyrat dhe arkat · Menaxho', 'flag' => 'fx.offices.manage', 'parent_flag' => 'fx.group.settings'],
    ['name' => 'Festat zyrtare · Menaxho', 'flag' => 'fx.holidays.manage', 'parent_flag' => 'fx.group.settings'],
    ['name' => 'Partnerët dhe shërbimet · Konfiguro', 'flag' => 'fx.partners.manage', 'parent_flag' => 'fx.group.settings'],
];
