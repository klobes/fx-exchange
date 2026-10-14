<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Emiron FX 0.8.3 — token-i i API-së ruhet te baza, i ENKRIPTUAR (FxCrypto / FX_DATA_KEY), jo te .env.
 * Kolona `secret` mban payload-in e enkriptuar; dekriptohet vetëm në momentin e përdorimit (kërkesa HTTP).
 * Rrotullimi i çelësit (fx:rotate-key) e rienkripton. Idempotent.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('fx_rate_sources') && ! Schema::hasColumn('fx_rate_sources', 'secret')) {
            Schema::table('fx_rate_sources', function (Blueprint $table): void {
                $table->text('secret')->nullable()->after('config');
            });
        }
    }

    public function down(): void
    {
        // Libra të rregulluar: s'fshihet asgjë.
    }
};
