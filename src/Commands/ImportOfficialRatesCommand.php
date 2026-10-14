<?php

namespace Botble\EmironFx\Commands;

use Botble\EmironFx\Services\BoaRateFetcher;
use Botble\EmironFx\Services\FxAlertService;
use Botble\EmironFx\Services\FxCalendar;
use Botble\EmironFx\Services\FxRateService;
use Botble\EmironFx\Services\SourceRegistry;
use Botble\EmironFx\Services\SourceSyncService;
use Illuminate\Console\Command;
use Throwable;

/**
 * php artisan fx:import-official [--dry-run] [--final]
 *
 *  --dry-run  lexon dhe shfaq kurset e BSH-së, pa ruajtur e pa njoftuar
 *  --final    përpjekja e fundit e ditës: nëse importi dështon ose BSH s'ka
 *             kurs për sot, dërgon njoftim "fut manualisht"
 *
 * Pas çdo importi të suksesshëm kontrollon shmangien e kurseve të zyrës dhe
 * lëvizjen e kursit zyrtar (FxAlertService::checkDeviation()).
 */
class ImportOfficialRatesCommand extends Command
{
    protected $signature = 'fx:import-official
        {--dry-run : Vetëm shfaq kurset e lexuara}
        {--final : Përpjekja e fundit e ditës (njofton për mungesë)}
        {--save-html= : Ruaj HTML-në e papërpunuar të BSH-së në këtë skedar (diagnostikim)}';

    protected $description = 'Emiron FX: importon kursin zyrtar të Bankës së Shqipërisë dhe njofton kur kurset e zyrës duhen rregulluar';

    public function handle(BoaRateFetcher $fetcher, FxRateService $rates, FxAlertService $alerts, SourceSyncService $sync): int
    {
        $started = now();
        $trigger = $this->option('dry-run') ? 'cli' : 'cron';
        $log = fn (string $status, array $extra = []) => $sync->logRun(
            ['code' => SourceRegistry::OFFICIAL, 'status' => $status, 'dry_run' => (bool) $this->option('dry-run')] + $extra,
            $started,
            $trigger
        );

        if (! config('plugins.emiron-fx.general.boa_import_enabled', true) && ! $this->option('dry-run')) {
            $this->warn('Importi nga BSH është i çaktivizuar (FX_BOA_IMPORT_ENABLED=false).');

            return self::SUCCESS;
        }

        // Festë zyrtare: BSH s'publikon; s'ka njoftim "s'u publikua".
        if (! $this->option('dry-run') && ($holiday = app(FxCalendar::class)->isHoliday(now()))) {
            $this->info("Sot është festë ({$holiday}): BSH s'publikon kurs.");

            return self::SUCCESS;
        }

        try {
            $html = $fetcher->fetchHtml();

            if ($path = $this->option('save-html')) {
                file_put_contents($path, $html);
                $this->info("HTML-ja u ruajt te {$path} (" . strlen($html) . ' bajte).');
            }

            $parsed = $fetcher->parse($html);
            $parsed['source_url'] = (string) config('plugins.emiron-fx.general.boa_url');
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            $log('failed', ['error' => $exception->getMessage()]);

            if ($this->option('final') && ! $this->option('dry-run')) {
                $alerts->importFailed($exception->getMessage());
            }

            return self::FAILURE;
        }

        $published = $parsed['published_at'];
        $this->info('BSH: ' . ($published ? $published->format('d.m.Y H:i:s') : 'pa datë') . ' — ' . $parsed['source_url']);

        foreach ($parsed['tables'] as $table) {
            $this->line('  ' . ($table['date']?->format('d.m.Y') ?? '—') . ': ' . collect($table['rates'])
                ->map(fn ($r, $code) => $code . '=' . $r['rate'] . ($r['unit'] > 1 ? '/' . $r['unit'] : ''))
                ->implode('  '));
        }

        if ($this->option('dry-run')) {
            $log('ok', ['data_date' => $published?->toDateString(), 'read' => collect($parsed['tables'])->sum(fn ($t) => count($t['rates']))]);

            return self::SUCCESS;
        }

        $result = $rates->recordImported($parsed);

        foreach (['saved' => 'U ruajt', 'unchanged' => 'E pandryshuar', 'skipped' => 'U anashkalua'] as $key => $label) {
            foreach ($result[$key] as $line) {
                $this->line("  [$label] $line");
            }
        }

        if (! $rates->importMatchedAnything($result)) {
            $message = 'BSH: asnjë monedhë e zyrës s\'u përputh me kurset e lexuara.';
            $this->error($message);
            $log('failed', ['error' => $message, 'data_date' => $published?->toDateString(), 'skipped' => $result['skipped']]);

            if ($this->option('final')) {
                $alerts->importFailed($message . ' ' . implode('; ', $result['skipped']));
            }

            return self::FAILURE;
        }

        $log(count($result['skipped']) > 0 ? 'partial' : 'ok', [
            'data_date' => $published?->toDateString(),
            'read' => count($result['saved']) + count($result['unchanged']) + count($result['skipped']),
            'saved' => count($result['saved']),
            'unchanged' => count($result['unchanged']),
            'skipped' => $result['skipped'],
        ]);

        $isToday = $published && $published->isSameDay(now());

        if (! $isToday && $this->option('final')) {
            $alerts->notPublished($published?->format('d.m.Y'));
        }

        if ($isToday) {
            $lines = $alerts->checkDeviation();
            $this->line($lines ? '  Njoftim: ' . count($lines) . ' monedha kërkojnë vëmendje.' : '  Kurset e zyrës janë brenda pragut.');
        }

        return self::SUCCESS;
    }
}
