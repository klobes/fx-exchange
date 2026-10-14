<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Emiron FX 0.7.0 — partnerët (AK-Invest etj.), banka dhe qarku i mbyllur.
 *
 *  fx_partners / fx_partner_services — partnerët dhe shërbimet (IN/OUT, mënyra e mbylljes).
 *  fx_partner_operations  — veprimet në sportel (të pandryshueshme, numër "P" pa boshllëqe, zinxhir hash).
 *  fx_partner_entries     — libri i partnerit (i pandryshueshëm): gjendja e parapaguar dhe detyrimi.
 *  fx_bank_accounts / fx_bank_entries — llogaritë dhe lëvizjet bankare (me konfirmim manual).
 *  fx_sequences.series    — seri numërimi më vete për veprimet e partnerëve ("P").
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('fx_sequences', 'series')) {
            Schema::table('fx_sequences', function (Blueprint $table): void {
                $table->string('series', 4)->default('FX')->after('year');
            });

            Schema::table('fx_sequences', function (Blueprint $table): void {
                $table->dropUnique(['office_id', 'year']);
                $table->unique(['office_id', 'year', 'series']);
            });
        }

        if (! Schema::hasTable('fx_partners')) {
            Schema::create('fx_partners', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 120);
                $table->string('code', 10)->unique();
                $table->decimal('prepaid_low_alert', 18, 2)->default(0);   // njoftim kur gjendja e parapaguar bie nën këtë (Lek)
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('fx_partner_services')) {
            Schema::create('fx_partner_services', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('partner_id')->constrained('fx_partners')->restrictOnDelete();
                $table->string('name', 120);
                $table->string('direction', 3);                    // in | out (nga ana e arkës)
                $table->string('settlement', 12);                  // prefunded (gjendje e parapaguar) | account (llogari me partnerin: rimbursim/pagesë)
                $table->json('currencies');                        // ["ALL","EUR",...]
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('fx_partner_operations')) {
            Schema::create('fx_partner_operations', function (Blueprint $table): void {
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
                $table->foreignId('partner_id')->constrained('fx_partners')->restrictOnDelete();
                $table->foreignId('service_id')->constrained('fx_partner_services')->restrictOnDelete();
                $table->string('direction', 3);
                $table->string('settlement', 12);
                $table->char('currency_code', 3);
                $table->decimal('amount', 18, 2);
                $table->string('reference', 80)->nullable();       // MTCN, nr. fature, etj.
                $table->unsignedBigInteger('voids_operation_id')->nullable()->unique();
                $table->string('void_reason', 500)->nullable();
                $table->char('prev_hash', 64)->nullable();
                $table->char('hash', 64);
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['office_id', 'year', 'seq']);
                $table->index(['business_date', 'partner_id']);
            });
        }

        if (! Schema::hasTable('fx_bank_accounts')) {
            Schema::create('fx_bank_accounts', function (Blueprint $table): void {
                $table->id();
                $table->string('bank_name', 120);
                $table->string('iban', 40)->nullable();
                $table->char('currency_code', 3);
                $table->decimal('opening_balance', 18, 2)->default(0);
                $table->date('opening_date');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('fx_bank_entries')) {
            Schema::create('fx_bank_entries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('bank_account_id')->constrained('fx_bank_accounts')->restrictOnDelete();
                $table->date('entry_date');
                // deposit_cash | withdraw_cash | partner_prefund | partner_reimbursement | partner_payment | other_in | other_out | reversal
                $table->string('type', 24);
                $table->decimal('amount', 18, 2);                  // + hyn në llogari, − del
                $table->foreignId('till_movement_id')->nullable()->unique()->constrained('fx_till_movements')->restrictOnDelete();
                $table->foreignId('partner_id')->nullable()->constrained('fx_partners')->restrictOnDelete();
                $table->unsignedBigInteger('reverses_entry_id')->nullable()->unique();
                $table->string('reference', 120)->nullable();
                $table->string('note', 500)->nullable();
                $table->string('status', 10)->default('pending');  // pending | confirmed
                $table->string('statement_ref', 120)->nullable();
                $table->unsignedBigInteger('confirmed_by')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['bank_account_id', 'entry_date']);
            });
        }

        if (! Schema::hasTable('fx_partner_entries')) {
            Schema::create('fx_partner_entries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('partner_id')->constrained('fx_partners')->restrictOnDelete();
                $table->date('entry_date');
                // prefund | consumption | receivable | reimbursement | payable | payment | adjustment | void
                $table->string('type', 16);
                $table->char('currency_code', 3);
                $table->decimal('prepaid_delta', 18, 2)->default(0);     // gjendja e parapaguar te partneri
                $table->decimal('receivable_delta', 18, 2)->default(0);  // + partneri na detyrohet, − ne i detyrohemi
                $table->foreignId('operation_id')->nullable()->constrained('fx_partner_operations')->restrictOnDelete();
                $table->foreignId('bank_entry_id')->nullable()->constrained('fx_bank_entries')->restrictOnDelete();
                $table->string('note', 500)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['partner_id', 'currency_code']);
            });
        }

        // Partneri dhe shërbimet fillestare — të editueshme nga paneli.
        if (DB::table('fx_partners')->count() === 0) {
            $now = now();
            $pid = DB::table('fx_partners')->insertGetId([
                'name' => 'AK-Invest', 'code' => 'AKI', 'prepaid_low_alert' => 50000, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            $services = [
                ['MoneyGram — tërheqje', 'out', 'account', ['ALL', 'EUR', 'USD', 'GBP']],
                ['MoneyGram — dërgesë', 'in', 'prefunded', ['ALL', 'EUR', 'USD']],
                ['Gjoba', 'in', 'prefunded', ['ALL']],
                ['Energji elektrike', 'in', 'prefunded', ['ALL']],
                ['Ujësjellës', 'in', 'prefunded', ['ALL']],
                ['Fatura interneti', 'in', 'prefunded', ['ALL']],
                ['Tatime', 'in', 'prefunded', ['ALL']],
            ];

            foreach ($services as $i => [$name, $dir, $settlement, $currencies]) {
                DB::table('fx_partner_services')->insert([
                    'partner_id' => $pid, 'name' => $name, 'direction' => $dir, 'settlement' => $settlement,
                    'currencies' => json_encode($currencies), 'is_active' => true, 'sort_order' => $i,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
    }
};
