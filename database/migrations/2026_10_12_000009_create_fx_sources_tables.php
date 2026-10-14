<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Emiron FX 0.8.1 — Qendra e burimeve të kurseve.
 *
 *  fx_rate_sources — burimet JSON të konfigurueshme (URL, mënyra e leximit, orientimi); të pa-aktivizuara parazgjedhur.
 *                    Burimet e ndërtuara në kod (erapi, ecb, fawaz, BSH/HTML) s'janë këtu: shfaqen nga SourceRegistry.
 *  fx_source_runs  — regjistër i pandryshueshëm i çdo përpjekjeje sinkronizimi (kur, rezultati, sa kurse, gabimi).
 *
 * Idempotent. Shtimi i burimit të ri = rresht i ri (ose nga paneli), pa kod.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('fx_rate_sources')) {
            Schema::create('fx_rate_sources', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 20)->unique();         // ≤ 20: ruhet te fx_reference_rates.source
                $table->string('name', 80);
                $table->string('role', 16)->default('reference'); // reference | official_mirror
                $table->string('driver', 10)->default('json');
                $table->string('url', 500);
                $table->string('fallback_url', 500)->nullable();
                $table->json('config');                         // mode, rates_path, orientation, date_path, auth, ...
                $table->boolean('enabled')->default(false);
                $table->string('attribution', 160)->nullable();  // tekst/link i kërkuar nga ofruesi kur shfaqet publikisht
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('fx_source_runs')) {
            Schema::create('fx_source_runs', function (Blueprint $table): void {
                $table->id();
                $table->string('source_code', 20);
                $table->string('triggered_by', 10);              // cron | user | cli
                $table->unsignedBigInteger('user_id')->nullable();
                $table->boolean('dry_run')->default(false);
                $table->string('status', 10);                    // ok | partial | failed | skipped
                $table->date('data_date')->nullable();
                $table->unsignedSmallInteger('rows_read')->default(0);
                $table->unsignedSmallInteger('rows_saved')->default(0);
                $table->unsignedSmallInteger('rows_unchanged')->default(0);
                $table->unsignedSmallInteger('rows_skipped')->default(0);
                $table->text('error')->nullable();
                $table->json('summary')->nullable();
                $table->dateTime('started_at');
                $table->dateTime('finished_at');
                $table->timestamp('created_at')->useCurrent();

                $table->index(['source_code', 'id']);
            });
        }

        if (DB::table('fx_rate_sources')->count() === 0) {
            $now = now();
            $rows = [
                [
                    'code' => 'floatrates', 'name' => 'FloatRates', 'role' => 'reference', 'sort_order' => 10,
                    'url' => 'https://www.floatrates.com/daily/all.json', 'fallback_url' => null, 'attribution' => null,
                    'config' => ['mode' => 'entries', 'rates_path' => '', 'entry_rate' => 'inverseRate', 'entry_date' => 'date', 'orientation' => 'lek_per_unit'],
                ],
                [
                    'code' => 'coinbase', 'name' => 'Coinbase (publik)', 'role' => 'reference', 'sort_order' => 20,
                    'url' => 'https://api.coinbase.com/v2/exchange-rates?currency=ALL', 'fallback_url' => null, 'attribution' => null,
                    'config' => ['mode' => 'map', 'rates_path' => 'data.rates', 'orientation' => 'per_lek'],
                ],
                [
                    'code' => 'wise', 'name' => 'Wise', 'role' => 'reference', 'sort_order' => 30,
                    'url' => 'https://api.wise.com/v1/rates?source={code}&target=ALL', 'fallback_url' => null, 'attribution' => null,
                    'config' => ['mode' => 'per_pair', 'rate_path' => '0.rate', 'time_path' => '0.time', 'orientation' => 'lek_per_unit', 'auth' => ['type' => 'bearer', 'config_key' => 'wise_token']],
                ],
                [
                    'code' => 'boa_mirror', 'name' => 'Pasqyra e BSH-së (AllRatesToday)', 'role' => 'official_mirror', 'sort_order' => 40,
                    'url' => 'https://allratestoday.com/api/open/central-bank/boa', 'fallback_url' => null, 'attribution' => 'allratestoday.com',
                    'config' => ['mode' => 'list', 'base_field' => 'base', 'quote_field' => 'quote', 'quote_value' => 'ALL', 'rate_field' => 'rate', 'date_field' => 'rate_date', 'orientation' => 'lek_per_unit'],
                ],
            ];

            foreach ($rows as $row) {
                DB::table('fx_rate_sources')->insert([
                    'code' => $row['code'], 'name' => $row['name'], 'role' => $row['role'], 'driver' => 'json',
                    'url' => $row['url'], 'fallback_url' => $row['fallback_url'], 'config' => json_encode($row['config']),
                    'enabled' => false, 'attribution' => $row['attribution'], 'sort_order' => $row['sort_order'],
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Libra të rregulluar: s'fshihet asgjë.
    }
};
