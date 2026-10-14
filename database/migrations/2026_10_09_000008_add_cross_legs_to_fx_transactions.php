<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Emiron FX 0.8.0 — kryqëzimi valutë↔valutë: dy këmbisa të lidhura në regjistër.
 *
 *  leg                 1 = zyra BLEN monedhën që jep klienti; 2 = zyra SHET monedhën që merr klienti (NULL = veprim i zakonshëm)
 *  pair_transaction_id vetëm te këmbisa 2: id e këmbisës 1 (këmbisa 1 s'përditësohet kurrë — regjistri është i pandryshueshëm)
 *  change_amount/_currency  kusuri që i kthehet klientit (te këmbisa 2), në Lek ose në monedhën e dhënë
 *
 * Kolona të reja opsionale: veprimet ekzistuese mbeten të pandryshuara dhe të verifikueshme (hash-i i përfshin
 * këto fusha vetëm kur ekzistojnë). Idempotent.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('fx_transactions')) {
            return;
        }

        if (! Schema::hasColumn('fx_transactions', 'leg')) {
            Schema::table('fx_transactions', function (Blueprint $table): void {
                $table->unsignedTinyInteger('leg')->nullable()->after('payment_method');
                $table->unsignedBigInteger('pair_transaction_id')->nullable()->unique()->after('leg');
                $table->decimal('change_amount', 18, 2)->nullable()->after('pair_transaction_id');
                $table->char('change_currency', 3)->nullable()->after('change_amount');
            });
        }
    }

    public function down(): void
    {
        // Libra të rregulluar: s'fshihet asgjë.
    }
};
