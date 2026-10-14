<?php

namespace Botble\EmironFx\Commands;

use Botble\EmironFx\Models\FxAccessLog;
use Botble\EmironFx\Models\FxOffice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * php artisan fx:purge-test-data --confirm=KODI_I_ZYRËS [--with-rates]
 *
 * VETËM para ditës së parë reale: fshin të dhënat e provës së sportelit
 * (veprimet, lëvizjet, seancat, numëruesit, klientët, dokumentet, rastet AML,
 * raportet, gjurmët e aksesit). Zyrat, arkat, monedhat dhe lejet mbeten.
 * Kurset mbeten, përveç kur jepet --with-rates.
 *
 * Mbrojtjet:
 *  - refuzon nëse ndonjë raport është shënuar i DORËZUAR (puna reale ka nisur);
 *  - kërkon kodin e zyrës dhe konfirmimin me shkrim "FSHI";
 *  - gjithçka në një transaksion DB; skedarët fshihen vetëm pas suksesit.
 */
class PurgeTestDataCommand extends Command
{
    protected $signature = 'fx:purge-test-data {--confirm= : Kodi i zyrës së parë (p.sh. Z1)} {--with-rates : Fshi edhe kurset e publikuara, kursin BSH dhe referencën}';

    protected $description = 'Emiron FX: fshin të dhënat e provës para vënies në punë (i pakthyeshëm)';

    public function handle(): int
    {
        $office = FxOffice::query()->orderBy('id')->first();

        if (! $office || $this->option('confirm') !== $office->code) {
            $this->error('Jep --confirm=' . ($office?->code ?? 'KODI') . ' (kodi i zyrës së parë).');

            return self::FAILURE;
        }

        if (Schema::hasTable('fx_reports') && DB::table('fx_reports')->whereNotNull('submitted_at')->exists()) {
            $this->error('NDALUAR: ka raporte të shënuara si të dorëzuara në BSH/AIF. Të dhënat s\'janë më provë.');

            return self::FAILURE;
        }

        $tables = [
            'fx_partner_entries', 'fx_bank_entries', 'fx_partner_operations',
            'fx_till_movements', 'fx_aml_cases', 'fx_customer_documents', 'fx_transactions',
            'fx_till_sessions', 'fx_sequences', 'fx_customers', 'fx_reports', 'fx_access_logs', 'fx_alerts',
        ];

        if ($this->option('with-rates')) {
            $tables = array_merge($tables, ['fx_rates', 'fx_official_rates', 'fx_reference_rates']);
        }

        $tables = array_values(array_filter($tables, fn ($t) => Schema::hasTable($t)));
        $counts = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]);

        $this->table(['Tabela', 'Rreshta'], $counts->map(fn ($c, $t) => [$t, $c])->values()->all());
        $this->warn('Do të fshihen të gjitha rreshtat më sipër. Veprimi NUK kthehet mbrapa.');

        if ($this->ask('Shkruaj FSHI për të vazhduar') !== 'FSHI') {
            $this->info('U anulua.');

            return self::FAILURE;
        }

        $files = array_merge(
            Schema::hasTable('fx_reports') ? DB::table('fx_reports')->pluck('file_path')->all() : [],
            Schema::hasTable('fx_customer_documents') ? DB::table('fx_customer_documents')->pluck('file_path')->all() : [],
        );

        DB::beginTransaction();

        try {
            if (Schema::hasTable('fx_transactions')) {
                // Partnerët/banka (0.7.0) para lëvizjeve të arkës që referojnë.
                if (Schema::hasTable('fx_partner_operations')) {
                    DB::table('fx_partner_entries')->delete();
                    DB::table('fx_bank_entries')->whereNotNull('reverses_entry_id')->delete();
                    DB::table('fx_bank_entries')->delete();
                    DB::table('fx_partner_operations')->whereNotNull('voids_operation_id')->delete();
                }

                // Anulimet (me voids_transaction_id) para origjinaleve.
                DB::table('fx_till_movements')->delete();
                DB::table('fx_aml_cases')->delete();
                DB::table('fx_transactions')->whereNotNull('voids_transaction_id')->delete();
            }

            foreach ($tables as $table) {
                DB::table($table)->delete();
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('DËSHTOI, asgjë s\'u fshi: ' . $e->getMessage());

            return self::FAILURE;
        }

        $disk = Storage::disk('local');

        foreach ($files as $file) {
            if ($file && $disk->exists($file)) {
                $disk->delete($file);
            }
        }

        FxAccessLog::record('purge_test', 'system', null, $counts->map(fn ($c, $t) => "$t:$c")->implode(', '));

        $this->info('✓ Të dhënat e provës u fshinë. Numërimi i veprimeve nis nga 000001; arka e parë hapet me numërimin fillestar.');

        return self::SUCCESS;
    }
}
