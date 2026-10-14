<?php

namespace Botble\EmironFx\Commands;

use Botble\EmironFx\Services\SourceRegistry;
use Botble\EmironFx\Services\SourceSyncService;
use Illuminate\Console\Command;

/**
 * php artisan fx:import-reference [--source=kodi ...] [--dry-run]
 *
 * 0.8.1: çdo përpjekje regjistrohet te fx_source_runs (shih faqen FX · Kurset → Burimet); bosh = të gjitha burimet e
 * referencës/pasqyrës të aktivizuara (FX_REFERENCE_SOURCES për ato të ndërtuara + burimet JSON të aktivizuara nga paneli).
 */
class ImportReferenceRatesCommand extends Command
{
    protected $signature = 'fx:import-reference {--source=* : kodi i burimit (bosh = të gjitha të aktivizuarat)} {--dry-run : Vetëm shfaq, pa ruajtur}';

    protected $description = 'Emiron FX: importon kurset e referencës së tregut (jo zyrtare) për krahasim me BSH-në';

    public function handle(SourceSyncService $sync, SourceRegistry $registry): int
    {
        $sources = $this->option('source') ?: $registry->enabledReferenceCodes();
        $dry = (bool) $this->option('dry-run');
        $failed = false;

        if (! $sources) {
            $this->warn('S\'ka burime referencë të aktivizuara (FX_REFERENCE_SOURCES ose paneli FX · Kurset → Burimet).');

            return self::SUCCESS;
        }

        foreach ($sources as $code) {
            if (! $registry->find($code)) {
                $this->error("Burim i panjohur: {$code}");
                $failed = true;

                continue;
            }

            $result = $sync->run($code, $dry, $dry ? 'cli' : 'cron');

            if ($result['status'] === 'failed') {
                $this->error($result['label'] . ': ' . $result['error']);
                $failed = true;

                continue;
            }

            $this->info($result['label'] . ' — ' . ($result['data_date'] ?? '—') . ' [' . $result['status'] . ']');

            foreach ($result['rows'] as $currency => $row) {
                $this->line(sprintf(
                    '  %s = %s Lek%s%s',
                    $currency,
                    number_format((float) $row['rate'], 4, '.', ''),
                    $row['deviation'] !== null ? sprintf('  (%+.2f%% nga BSH)', $row['deviation']) : '',
                    ! empty($row['skipped']) ? '  HEDHUR' : ''
                ));
            }

            foreach ($result['errors'] as $error) {
                $this->warn('  ! ' . $error);
            }

            if (! $dry) {
                $this->line("  u ruajtën {$result['saved']}, të pandryshuara {$result['unchanged']}, të hedhura " . count($result['skipped']));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
