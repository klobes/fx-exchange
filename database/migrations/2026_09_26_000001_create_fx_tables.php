<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Emiron FX 0.1.0 — Faza 1: monedhat, kurset e zyrës, kursi zyrtar i BSH-së.
 *
 * fx_rates dhe fx_official_rates janë TË PANDRYSHUESHME: pa updated_at,
 * modelet hedhin përjashtim te updating/deleting (parimi i RULE_051).
 * Çdo korrigjim = rresht i ri. Historia mbetet e plotë për mbikëqyrjen.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('fx_currencies')) {
            Schema::create('fx_currencies', function (Blueprint $table): void {
                $table->id();
                $table->char('code', 3)->unique();          // ISO 4217: EUR, USD...
                $table->string('name', 80);
                $table->string('symbol', 10)->nullable();
                $table->unsignedTinyInteger('decimals')->default(2);
                $table->unsignedInteger('unit')->default(1); // kursi kuotohet për N njësi (p.sh. 100 JPY)
                $table->boolean('is_active')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });

            $now = now();
            DB::table('fx_currencies')->insert([
                ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'unit' => 1, 'is_active' => false, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
                ['code' => 'USD', 'name' => 'Dollar amerikan', 'symbol' => '$', 'decimals' => 2, 'unit' => 1, 'is_active' => false, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
                ['code' => 'GBP', 'name' => 'Paund britanik', 'symbol' => '£', 'decimals' => 2, 'unit' => 1, 'is_active' => false, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
                ['code' => 'CHF', 'name' => 'Frank zviceran', 'symbol' => 'CHF', 'decimals' => 2, 'unit' => 1, 'is_active' => false, 'sort_order' => 4, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }

        if (! Schema::hasTable('fx_rates')) {
            Schema::create('fx_rates', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('currency_id')->constrained('fx_currencies')->restrictOnDelete();
                $table->decimal('buy_rate', 18, 6);        // zyra blen valutën (klienti shet)
                $table->decimal('sell_rate', 18, 6);       // zyra shet valutën (klienti blen)
                $table->decimal('official_rate', 18, 6)->nullable();   // snapshot i kursit BSH në momentin e publikimit
                $table->date('official_rate_date')->nullable();
                $table->decimal('buy_deviation_pct', 9, 4)->nullable();
                $table->decimal('sell_deviation_pct', 9, 4)->nullable();
                $table->boolean('is_override')->default(false);
                $table->dateTime('effective_at');
                $table->uuid('batch_uuid');
                $table->string('note', 500)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['currency_id', 'effective_at']);
                $table->index('batch_uuid');
            });
        }

        if (! Schema::hasTable('fx_official_rates')) {
            Schema::create('fx_official_rates', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('currency_id')->constrained('fx_currencies')->restrictOnDelete();
                $table->date('rate_date');
                $table->decimal('rate', 18, 6);             // Lek për `unit` njësi, sipas BSH
                $table->string('source', 20)->default('boa_manual');
                $table->string('note', 500)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('created_at')->useCurrent();

                // Korrigjimi i një dite = rresht i ri me të njëjtën datë; vlen i fundit.
                $table->index(['currency_id', 'rate_date', 'id']);
            });
        }
    }

    public function down(): void
    {
        // Qëllimisht s'fshihet asgjë: libra të rregulluar (shih Plugin::remove()).
    }
};
