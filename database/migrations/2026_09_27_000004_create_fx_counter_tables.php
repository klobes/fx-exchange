<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Emiron FX 0.4.0 — Faza 2a: zyrat, arkat, seancat, libri i arkës dhe regjistri i veprimeve.
 *
 * Parimet:
 *  - fx_till_movements dhe fx_transactions janë të PANDRYSHUESHME (trait Immutable);
 *    anulimi = veprim i kundërt, jo fshirje.
 *  - Gjendja e arkës = shuma e lëvizjeve të saj (vazhdimisht, përtej seancave);
 *    diferencat e numërimit në hapje/mbyllje janë lëvizje më vete, me arsye.
 *  - Numri i veprimit: pa boshllëqe, për zyrë dhe vit (fx_sequences, me kyçje rreshti).
 *  - Zinxhir hash për zyrë: çdo veprim mban hash-in e të mëparshmit.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('fx_offices')) {
            Schema::create('fx_offices', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 120);
                $table->string('legal_name', 160)->nullable();
                $table->string('nipt', 20)->nullable();
                $table->string('license_no', 60)->nullable();
                $table->string('address', 255)->nullable();
                $table->string('phone', 40)->nullable();
                $table->string('code', 10)->unique();          // prefiksi i numrit të kuponit
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });

            DB::table('fx_offices')->insert([
                'name' => 'Zyra kryesore', 'code' => 'Z1', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        if (! Schema::hasTable('fx_tills')) {
            Schema::create('fx_tills', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('office_id')->constrained('fx_offices')->restrictOnDelete();
                $table->string('name', 80);
                $table->unsignedBigInteger('user_id')->nullable();   // arkëtari i caktuar
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique('user_id');                            // një arkëtar = një arkë
            });
        }

        if (! Schema::hasTable('fx_till_sessions')) {
            Schema::create('fx_till_sessions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('till_id')->constrained('fx_tills')->restrictOnDelete();
                $table->unsignedBigInteger('user_id');
                $table->date('business_date');
                $table->string('status', 12)->default('open');       // open | closed | approved
                $table->json('opening_counts')->nullable();
                $table->json('closing_counts')->nullable();
                $table->json('closing_expected')->nullable();
                $table->string('opening_note', 500)->nullable();
                $table->string('closing_note', 500)->nullable();
                $table->timestamp('opened_at')->useCurrent();
                $table->timestamp('closed_at')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();

                $table->index(['till_id', 'status']);
                $table->index('business_date');
            });
        }

        if (! Schema::hasTable('fx_sequences')) {
            Schema::create('fx_sequences', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('office_id')->constrained('fx_offices')->restrictOnDelete();
                $table->unsignedSmallInteger('year');
                $table->unsignedInteger('last_seq')->default(0);
                $table->char('last_hash', 64)->nullable();
                $table->timestamps();

                $table->unique(['office_id', 'year']);
            });
        }

        if (! Schema::hasTable('fx_transactions')) {
            Schema::create('fx_transactions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('office_id')->constrained('fx_offices')->restrictOnDelete();
                $table->unsignedSmallInteger('year');
                $table->unsignedInteger('seq');
                $table->string('number', 24);
                $table->foreignId('till_id')->constrained('fx_tills')->restrictOnDelete();
                $table->foreignId('till_session_id')->constrained('fx_till_sessions')->restrictOnDelete();
                $table->unsignedBigInteger('user_id');
                $table->date('business_date');
                $table->dateTime('occurred_at');
                $table->string('direction', 4);                       // buy = zyra blen valutë | sell = zyra shet
                $table->foreignId('currency_id')->constrained('fx_currencies')->restrictOnDelete();
                $table->char('currency_code', 3);
                $table->unsignedInteger('unit')->default(1);
                $table->decimal('foreign_amount', 18, 2);
                $table->decimal('rate', 18, 6);
                $table->decimal('lek_amount', 18, 2);
                $table->foreignId('rate_id')->nullable()->constrained('fx_rates')->restrictOnDelete();
                $table->decimal('official_rate', 18, 6)->nullable();
                $table->date('official_rate_date')->nullable();
                $table->decimal('eur_equivalent', 18, 2)->nullable();
                $table->string('client_category', 60);
                $table->string('payment_method', 10)->default('cash');
                $table->json('flags')->nullable();                    // cdd, aif_cash, boa_individual, holding_warn
                $table->text('cdd_reference')->nullable();             // ref. e dokumentit, E ENKRIPTUAR (cast encrypted)
                $table->unsignedBigInteger('voids_transaction_id')->nullable()->unique();
                $table->string('void_reason', 500)->nullable();
                $table->char('prev_hash', 64)->nullable();
                $table->char('hash', 64);
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['office_id', 'year', 'seq']);
                $table->index(['business_date', 'direction']);
                $table->index('till_session_id');
            });
        }

        if (! Schema::hasTable('fx_till_movements')) {
            Schema::create('fx_till_movements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('till_id')->constrained('fx_tills')->restrictOnDelete();
                $table->foreignId('till_session_id')->constrained('fx_till_sessions')->restrictOnDelete();
                $table->char('currency_code', 3);                     // ALL + kodet e fx_currencies
                $table->decimal('amount', 18, 2);                     // + hyn në arkë, − del
                $table->string('type', 20);                           // initial | opening_variance | transaction | void | cash_in | cash_out | closing_variance
                $table->foreignId('transaction_id')->nullable()->constrained('fx_transactions')->restrictOnDelete();
                $table->string('note', 500)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['till_id', 'currency_code']);
                $table->index('till_session_id');
            });
        }
    }

    public function down(): void
    {
        // Libra të rregulluar: s'fshihen.
    }
};
