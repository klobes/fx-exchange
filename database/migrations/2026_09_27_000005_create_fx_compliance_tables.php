<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Emiron FX 0.5.0 — Fazat 2b/2c/2d.
 *
 * fx_customers    — klientët e identifikuar. Të dhënat personale (emri, dokumenti,
 *                   datëlindja, adresa, telefoni) të ENKRIPTUARA (cast encrypted);
 *                   kërkimi me dokument bëhet me "blind index" (HMAC), pa dekriptim.
 * fx_aml_cases    — rastet e dyshimta (vetëm compliance).
 * fx_reports      — çdo raport i gjeneruar (BSH, AIF): skedari, checksum-i, dorëzimi.
 * fx_access_logs  — kush pa/ndryshoi të dhëna personale dhe kur.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('fx_customers')) {
            Schema::create('fx_customers', function (Blueprint $table): void {
                $table->id();
                $table->string('type', 12)->default('person');        // person | company
                $table->string('client_category', 60);                 // lista e BSH-së
                $table->text('first_name')->nullable();                // encrypted
                $table->text('last_name')->nullable();                 // encrypted
                $table->string('legal_name', 160)->nullable();         // subjekt: publik
                $table->string('nipt', 20)->nullable()->index();
                $table->string('doc_type', 20);
                $table->text('doc_number');                            // encrypted
                $table->char('doc_hash', 64)->unique();                // HMAC(doc_type|doc_number)
                $table->string('doc_country', 3)->nullable();
                $table->date('doc_expiry')->nullable();
                $table->string('nationality', 3)->nullable();
                $table->text('birth_date')->nullable();                // encrypted
                $table->text('address')->nullable();                   // encrypted
                $table->text('phone')->nullable();                     // encrypted
                $table->boolean('is_pep')->default(false);
                $table->string('risk_level', 10)->default('low');      // low | medium | high
                $table->boolean('is_blocked')->default(false);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('fx_transactions') && ! Schema::hasColumn('fx_transactions', 'customer_id')) {
            Schema::table('fx_transactions', function (Blueprint $table): void {
                $table->foreignId('customer_id')->nullable()->after('client_category')->constrained('fx_customers')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('fx_aml_cases')) {
            Schema::create('fx_aml_cases', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->nullable()->constrained('fx_customers')->restrictOnDelete();
                $table->foreignId('transaction_id')->nullable()->constrained('fx_transactions')->restrictOnDelete();
                $table->string('status', 12)->default('open');         // open | reported | closed
                $table->text('description');                           // encrypted
                $table->text('decision')->nullable();                  // encrypted
                $table->string('fiu_reference', 120)->nullable();
                $table->timestamp('reported_at')->nullable();
                $table->unsignedBigInteger('opened_by');
                $table->unsignedBigInteger('closed_by')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('fx_reports')) {
            Schema::create('fx_reports', function (Blueprint $table): void {
                $table->id();
                $table->string('type', 20);                            // boa_daily | aif_ctr
                $table->date('report_date');
                $table->unsignedInteger('version')->default(1);
                $table->string('file_path', 255);
                $table->char('checksum', 64);
                $table->json('summary')->nullable();
                $table->unsignedBigInteger('generated_by')->nullable();
                $table->timestamp('generated_at')->useCurrent();
                $table->timestamp('submitted_at')->nullable();
                $table->unsignedBigInteger('submitted_by')->nullable();
                $table->string('submission_ref', 120)->nullable();

                $table->unique(['type', 'report_date', 'version']);
            });
        }

        if (! Schema::hasTable('fx_access_logs')) {
            Schema::create('fx_access_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('action', 40);                          // view | search | create | update | report | block ...
                $table->string('subject_type', 40);
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->string('ip', 45)->nullable();
                $table->string('note', 255)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['subject_type', 'subject_id']);
            });
        }
    }

    public function down(): void
    {
        // Libra të rregulluar: s'fshihen.
    }
};
