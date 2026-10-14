<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 0.8.4 — të dhëna më të plota të llogarisë bankare (përveç IBAN): nr. llogarie, SWIFT, dega, mbajtësi, kontakti, shënim. */
return new class extends Migration
{
    private const COLUMNS = [
        'account_number' => ['string', 40],
        'swift' => ['string', 20],
        'branch' => ['string', 120],
        'account_holder' => ['string', 160],
        'contact_person' => ['string', 120],
        'contact_phone' => ['string', 40],
        'note' => ['string', 500],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $name => [$type, $length]) {
            if (! Schema::hasColumn('fx_bank_accounts', $name)) {
                Schema::table('fx_bank_accounts', function (Blueprint $table) use ($name, $length): void {
                    $table->string($name, $length)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::COLUMNS) as $name) {
            if (Schema::hasColumn('fx_bank_accounts', $name)) {
                Schema::table('fx_bank_accounts', function (Blueprint $table) use ($name): void {
                    $table->dropColumn($name);
                });
            }
        }
    }
};
