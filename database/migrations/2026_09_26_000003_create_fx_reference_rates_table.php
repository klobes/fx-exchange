<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Emiron FX 0.3.0 — kurset e referencës së tregut (ExchangeRate-API, BQE via
 * Frankfurter). S'janë kurs zyrtar: shërbejnë vetëm për krahasim me BSH-në
 * (panel + njoftime). Vetëm shtohen; s'ndryshohen.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('fx_reference_rates')) {
            return;
        }

        Schema::create('fx_reference_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('currency_id')->constrained('fx_currencies')->restrictOnDelete();
            $table->string('source', 20);          // erapi | ecb
            $table->date('rate_date');
            $table->decimal('rate', 18, 6);         // Lek për `unit` njësi (si kursi zyrtar)
            $table->string('basis', 120)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['currency_id', 'source', 'rate_date', 'id']);
        });
    }

    public function down(): void
    {
    }
};
