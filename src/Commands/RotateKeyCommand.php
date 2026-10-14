<?php

namespace Botble\EmironFx\Commands;

use Botble\EmironFx\Models\FxAccessLog;
use Botble\EmironFx\Models\FxCustomer;
use Botble\EmironFx\Models\FxReport;
use Botble\EmironFx\Support\FxCrypto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * php artisan fx:rotate-key [--new=base64:...] [--dry-run]
 *
 * Ndërron çelësin e të dhënave personale të Emiron FX:
 *  1. kontrollon që ÇDO vlerë e enkriptuar dekriptohet me çelësin aktual (përndryshe ndalon);
 *  2. brenda një transaksioni DB: rienkripton kolonat dhe rillogarit `doc_hash`;
 *  3. shkruan .env (me backup): FX_DATA_KEY = i riu, FX_DATA_PREVIOUS_KEYS += i vjetri;
 *  4. rienkripton skedarët e enkriptuar të raporteve (AIF).
 *
 * Herën e parë (pa FX_DATA_KEY) e kalon të dhënat nga APP_KEY te një çelës i veçantë.
 * Çelësi i vjetër mbetet te FX_DATA_PREVIOUS_KEYS vetëm për lexim — hiqe pasi të
 * verifikosh gjithçka dhe të kesh backup të ri.
 *
 * Ekzekutoje si përdoruesi që ka të drejtë shkrimi te .env.
 */
class RotateKeyCommand extends Command
{
    protected $signature = 'fx:rotate-key {--new= : Çelësi i ri (bosh = gjenerohet)} {--dry-run : Vetëm kontrollo, mos ndrysho asgjë}';

    protected $description = 'Emiron FX: ndërron çelësin e të dhënave personale (rienkriptim + .env)';

    /** tabela => [kolonat e enkriptuara] */
    private const COLUMNS = [
        'fx_customers' => ['first_name', 'last_name', 'doc_number', 'birth_date', 'address', 'phone'],
        'fx_aml_cases' => ['description', 'decision'],
        'fx_transactions' => ['cdd_reference'],
        'fx_rate_sources' => ['secret'], // 0.8.3: token-et e API-ve (enkriptuar)
    ];

    public function handle(): int
    {
        $old = FxCrypto::currentKey();
        $previous = FxCrypto::previousKeys();
        $new = $this->option('new') ?: FxCrypto::generateKey();

        try {
            FxCrypto::encrypter($new);
        } catch (Throwable $e) {
            $this->error('Çelësi i ri është i pavlefshëm: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($new === $old) {
            $this->error('Çelësi i ri është i njëjtë me atë aktual.');

            return self::FAILURE;
        }

        $this->info(FxCrypto::usesDedicatedKey() ? 'Çelësi aktual: FX_DATA_KEY.' : 'Çelësi aktual: APP_KEY (s\'ka FX_DATA_KEY) — të dhënat do kalojnë te një çelës i veçantë.');

        // 1. Kontrolli paraprak: çdo vlerë duhet të dekriptohet.
        $failures = [];
        $count = 0;

        foreach (self::COLUMNS as $table => $columns) {
            DB::table($table)->select(array_merge(['id'], $columns))->orderBy('id')->chunk(500, function ($rows) use ($table, $columns, &$failures, &$count): void {
                foreach ($rows as $row) {
                    foreach ($columns as $col) {
                        if ($row->{$col} === null || $row->{$col} === '') {
                            continue;
                        }

                        try {
                            FxCrypto::decrypt($row->{$col});
                            $count++;
                        } catch (Throwable) {
                            $failures[] = "{$table}#{$row->id}.{$col}";
                        }
                    }
                }
            });
        }

        $this->line("Vlera të enkriptuara: {$count}; që s'dekriptohen: " . count($failures));

        if ($failures) {
            $this->error('NDALUAR: disa vlera s\'dekriptohen me çelësin aktual. Asgjë s\'u ndryshua.');

            foreach (array_slice($failures, 0, 20) as $f) {
                $this->line("  - {$f}");
            }

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Kontrolli OK. (--dry-run: asgjë s\'u ndryshua)');

            return self::SUCCESS;
        }

        if (! $this->confirm('Të vazhdoj me rienkriptimin dhe përditësimin e .env?', true)) {
            return self::FAILURE;
        }

        $envPath = base_path('.env');
        $envBackup = null;

        DB::beginTransaction();

        try {
            $oldEncrypter = fn (string $payload) => FxCrypto::decrypt($payload);
            $newEncrypter = FxCrypto::encrypter($new);

            foreach (self::COLUMNS as $table => $columns) {
                DB::table($table)->select(array_merge(['id'], $columns, $table === 'fx_customers' ? ['doc_type'] : []))
                    ->orderBy('id')->lazyById(500)
                    ->each(function ($row) use ($table, $columns, $oldEncrypter, $newEncrypter, $new): void {
                        $update = [];

                        foreach ($columns as $col) {
                            if ($row->{$col} !== null && $row->{$col} !== '') {
                                $plain = $oldEncrypter($row->{$col});
                                $update[$col] = $newEncrypter->encryptString($plain);

                                if ($table === 'fx_customers' && $col === 'doc_number') {
                                    $update['doc_hash'] = FxCustomer::docHash((string) $row->doc_type, $plain, $new);
                                }
                            }
                        }

                        if ($update) {
                            DB::table($table)->where('id', $row->id)->update($update);
                        }
                    });
            }

            $envBackup = $this->writeEnv($envPath, $new, array_values(array_unique(array_merge([$old], $previous))));

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            if ($envBackup && is_file($envBackup)) {
                copy($envBackup, $envPath);
            }

            $this->error('DËSHTOI, u kthye gjithçka mbrapa: ' . $e->getMessage());

            return self::FAILURE;
        }

        // Nga këtu e tutje: çelësi i ri aktual, i vjetri vetëm për lexim.
        FxCrypto::useKeys($new, array_values(array_unique(array_merge([$old], $previous))));
        $this->reencryptReportFiles($old, $previous, $new);
        FxAccessLog::record('rotate_key', 'system', null, 'FX_DATA_KEY u ndërrua');

        Artisan::call('config:clear');

        $this->info('✓ Çelësi u ndërrua. .env u përditësua (backup: ' . basename((string) $envBackup) . ').');
        $this->warn('Tani: ekzekuto `sudo -u www-data php artisan optimize:clear`, provo një kërkim klienti,');
        $this->warn('ruaj FX_DATA_KEY të ri në një vend të sigurt, bëj backup të ri, dhe pastaj hiq çelësin e vjetër nga FX_DATA_PREVIOUS_KEYS.');

        return self::SUCCESS;
    }

    /** Shkruan FX_DATA_KEY dhe FX_DATA_PREVIOUS_KEYS në .env; kthen shtegun e backup-it. */
    private function writeEnv(string $path, string $new, array $previous): string
    {
        if (! is_file($path) || ! is_writable($path)) {
            throw new \RuntimeException(".env s'është i shkruajtshëm nga ky përdorues ({$path}).");
        }

        $backup = $path . '.fx-backup-' . date('Ymd_His');
        copy($path, $backup);
        @chmod($backup, 0600);

        $content = (string) file_get_contents($path);
        $set = function (string $content, string $key, string $value): string {
            $line = $key . '="' . $value . '"';

            return preg_match('/^' . $key . '=.*$/m', $content)
                ? preg_replace('/^' . $key . '=.*$/m', $line, $content)
                : rtrim($content, "\n") . "\n" . $line . "\n";
        };

        $content = $set($content, 'FX_DATA_KEY', $new);
        $content = $set($content, 'FX_DATA_PREVIOUS_KEYS', implode(',', $previous));

        $tmp = $path . '.fx-tmp';
        file_put_contents($tmp, $content);
        @chmod($tmp, fileperms($path) & 0777);
        rename($tmp, $path);

        return $backup;
    }

    private function reencryptReportFiles(string $old, array $previous, string $new): void
    {
        $disk = Storage::disk('local');

        // Listat AIF të 0.5.0 u ruajtën pa enkriptim: enkriptohen tani (.csv → .csv.enc).
        FxReport::query()->where('type', 'aif_ctr')->where('file_path', 'like', '%.csv')->each(function (FxReport $report) use ($disk, $new): void {
            if (! $disk->exists($report->file_path)) {
                return;
            }

            $target = $report->file_path . '.enc';
            $disk->put($target, FxCrypto::encrypter($new)->encryptString((string) $disk->get($report->file_path)));
            $disk->delete($report->file_path);
            $report->forceFill(['file_path' => $target, 'checksum' => hash_file('sha256', $disk->path($target))])->save();
        });

        // Kopjet e dokumenteve (0.6.0).
        if (\Illuminate\Support\Facades\Schema::hasTable('fx_customer_documents')) {
            DB::table('fx_customer_documents')->orderBy('id')->each(function ($doc) use ($disk, $new): void {
                if (! $disk->exists($doc->file_path)) {
                    return;
                }

                try {
                    $plain = FxCrypto::decrypt($disk->get($doc->file_path));
                    $disk->put($doc->file_path, FxCrypto::encrypter($new)->encryptString((string) $plain));
                    DB::table('fx_customer_documents')->where('id', $doc->id)->update(['checksum' => hash_file('sha256', $disk->path($doc->file_path))]);
                } catch (Throwable $e) {
                    $this->warn("Dokumenti #{$doc->id} s'u rienkriptua: {$e->getMessage()} (lexohet ende me çelësin e vjetër).");
                }
            });
        }

        FxReport::query()->where('file_path', 'like', '%.enc')->each(function (FxReport $report) use ($disk, $new): void {
            if (! $disk->exists($report->file_path)) {
                return;
            }

            try {
                $plain = FxCrypto::decrypt($disk->get($report->file_path));
                $disk->put($report->file_path, FxCrypto::encrypter($new)->encryptString($plain));
                $report->forceFill(['checksum' => hash_file('sha256', $disk->path($report->file_path))])->save();
            } catch (Throwable $e) {
                $this->warn("Raporti #{$report->id} s'u rienkriptua: {$e->getMessage()} (lexohet ende me çelësin e vjetër).");
            }
        });
    }
}
