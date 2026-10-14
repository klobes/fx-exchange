<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Emiron FX 0.7.6 — përgatit `fx_sequences.series` PARA migrimit 2026_10_02_000007.
 *
 * Problemi: migrimi i 0.7.0 e heq indeksin unik (office_id, year) para se të krijojë atë të ri, dhe MySQL
 * refuzon (error 1553: "needed in a foreign key constraint", sepse çelësi i huaj te office_id mbështetet te ai indeks).
 * Ky migrim ka emër që renditet para tij dhe e bën ndërrimin në rendin e duhur:
 *   1) shton kolonën `series` (nëse mungon);
 *   2) krijon indeksin e ri unik (office_id, year, series) — tani çelësi i huaj ka mbështetje tjetër;
 *   3) heq indeksin e vjetër (office_id, year).
 * Pastaj migrimi 000007 sheh që kolona `series` ekziston dhe e kalon bllokun e vet (s'preket asnjë skedar ekzistues).
 *
 * Idempotent: në një bazë ku ndërrimi është bërë (me dorë ose nga 000007) s'bën asgjë. Vetëm MySQL/MariaDB
 * (përdor information_schema); për drajvera të tjerë s'bën asgjë.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) || ! Schema::hasTable('fx_sequences')) {
            return;
        }

        $table = DB::getTablePrefix() . 'fx_sequences';
        $hasIndex = static fn (string $name): bool => DB::selectOne(
            'select 1 as found from information_schema.statistics where table_schema = database() and table_name = ? and index_name = ? limit 1',
            [$table, $name]
        ) !== null;

        if (! Schema::hasColumn('fx_sequences', 'series')) {
            Schema::table('fx_sequences', function (Blueprint $table): void {
                $table->string('series', 4)->default('FX')->after('year');
            });
        }

        if (! $hasIndex($table . '_office_id_year_series_unique')) {
            Schema::table('fx_sequences', function (Blueprint $table): void {
                $table->unique(['office_id', 'year', 'series']);
            });
        }

        if ($hasIndex($table . '_office_id_year_unique')) {
            Schema::table('fx_sequences', function (Blueprint $table): void {
                $table->dropUnique(['office_id', 'year']);
            });
        }
    }

    public function down(): void
    {
        // Libra të rregulluar: s'kthehet asgjë.
    }
};
