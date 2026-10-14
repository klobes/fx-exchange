<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Emiron FX 0.8.2 — kursi i rekomanduar nga tregu.
 *
 *  fx_source_settings — cilat burime hyjnë në "mesataren e rekomanduar" (vetëm Wise parazgjedhur).
 *  fx_market_margins  — marzhet për monedhë kur mënyra është % ose Lek (blerje poshtë mid, shitje sipër mid).
 *  fx_market_settings — cilësime të thjeshta (mënyra e marzhit, pragu i lëvizjes, min. burime).
 *
 * Idempotent. Asgjë s'publikon kurs vetë: rekomandimi është vetëm sugjerim te "Publiko kurset".
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('fx_source_settings')) {
            Schema::create('fx_source_settings', function (Blueprint $table): void {
                $table->string('source_code', 20)->primary();
                $table->boolean('use_in_average')->default(false);
                $table->timestamps();
            });

            $now = now();
            DB::table('fx_source_settings')->insert(['source_code' => 'wise', 'use_in_average' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        if (! Schema::hasTable('fx_market_margins')) {
            Schema::create('fx_market_margins', function (Blueprint $table): void {
                $table->unsignedBigInteger('currency_id')->primary();
                $table->decimal('buy_pct', 8, 4)->nullable();    // blerja = mid × (1 − buy_pct/100)
                $table->decimal('sell_pct', 8, 4)->nullable();   // shitja = mid × (1 + sell_pct/100)
                $table->decimal('buy_lek', 12, 4)->nullable();   // blerja = mid − buy_lek
                $table->decimal('sell_lek', 12, 4)->nullable();  // shitja = mid + sell_lek
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('fx_market_settings')) {
            Schema::create('fx_market_settings', function (Blueprint $table): void {
                $table->string('key', 40)->primary();
                $table->string('value', 120)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Libra të rregulluar: s'fshihet asgjë.
    }
};
