<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Emiron FX 0.6.0
 *  fx_holidays          — festat zyrtare (afatet e raporteve, kujtesat, importi i BSH-së).
 *                         Të fiksuarat dhe Pashkët llogariten vetë; Bajramet shtohen nga paneli.
 *  fx_customer_documents — kopja e dokumentit të identifikimit, skedar i ENKRIPTUAR me FX_DATA_KEY.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('fx_holidays')) {
            Schema::create('fx_holidays', function (Blueprint $table): void {
                $table->id();
                $table->date('holiday_date')->unique();
                $table->string('name', 120);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('fx_customer_documents')) {
            Schema::create('fx_customer_documents', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained('fx_customers')->restrictOnDelete();
                $table->string('side', 10)->default('front');        // front | back | other
                $table->string('file_path', 255);                     // storage/app/fx-documents/... (.enc)
                $table->string('mime', 60);
                $table->unsignedInteger('size');
                $table->char('checksum', 64);                         // sha256 i skedarit të enkriptuar
                $table->unsignedBigInteger('uploaded_by')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
    }
};
