<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Emiron FX 0.2.0 — regjistri i njoftimeve (import i dështuar, shmangie,
 * lëvizje e kursit zyrtar). Shërben edhe si mbrojtje nga dërgimi i dyfishtë:
 * i njëjti njoftim (lloj + ditë + gjurmë) dërgohet një herë.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('fx_alerts')) {
            return;
        }

        Schema::create('fx_alerts', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 40);                 // deviation | import_failed | not_published
            $table->date('alert_date');
            $table->char('fingerprint', 40);
            $table->json('payload')->nullable();
            $table->text('recipients')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['type', 'alert_date', 'fingerprint']);
        });
    }

    public function down(): void
    {
        // Regjistër auditimi: s'fshihet.
    }
};
