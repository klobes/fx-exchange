<?php

namespace Botble\EmironFx\Services;

use Botble\EmironFx\Models\FxCurrency;
use Botble\EmironFx\Models\FxSourceRun;
use Carbon\Carbon;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Sinkronizimi i burimeve me regjistër përpjekjesh (0.8.1).
 *
 * Çdo përpjekje (cron, përdorues, komandë) regjistrohet te fx_source_runs: kur, rezultati, sa kurse u lexuan/ruajtën,
 * çfarë u hodh dhe gabimi. Kështu "a po punon sinkronizimi?" ka përgjigje të dukshme.
 *
 * Kontrolli i cilësisë (referenca/pasqyra): një kurs që largohet nga kursi zyrtar i BSH-së mbi `source_outlier_percent`
 * (parazgjedhje 15%) HIDHET (nuk ruhet) dhe raportohet — mbron nga parsim i prishur ose vlerë e çuditshme.
 * Asnjë burim s'publikon kurs të zyrës; vetëm BSH ndikon te kontrolli i shmangies.
 */
class SourceSyncService
{
    public function __construct(
        private readonly SourceRegistry $registry,
        private readonly ReferenceRateService $references,
        private readonly FxRateService $rates,
        private readonly BoaRateFetcher $boa,
        private readonly FxAlertService $alerts,
    ) {
    }

    /**
     * Ekzekuton një burim (dhe e regjistron).
     *
     * @param  string  $triggeredBy  cron | user | cli
     * @return array{
     *   code: string, label: string, status: string, data_date: ?string, read: int, saved: int, unchanged: int,
     *   rows: array<string, array{rate: string, official: ?string, deviation: ?float}>,
     *   skipped: array<int, string>, errors: array<int, string>, error: ?string, dry_run: bool
     * }
     */
    public function run(string $code, bool $dryRun = false, string $triggeredBy = 'cli', ?int $userId = null): array
    {
        $source = $this->registry->find($code);

        if (! $source) {
            throw new InvalidArgumentException("Burim i panjohur: {$code}");
        }

        $started = now();
        $result = [
            'code' => $code, 'label' => (string) $source['name'], 'status' => 'ok', 'data_date' => null, 'read' => 0,
            'saved' => 0, 'unchanged' => 0, 'rows' => [], 'skipped' => [], 'errors' => [], 'error' => null, 'dry_run' => $dryRun,
        ];

        try {
            if ($source['driver'] === 'boa') {
                $this->syncOfficial($result, $dryRun);
            } else {
                $this->syncReference($result, $code, $dryRun);
            }

            $result['status'] = ($result['errors'] || $result['skipped']) ? 'partial' : 'ok';
        } catch (Throwable $exception) {
            $result['status'] = 'failed';
            $result['error'] = $exception->getMessage();
        }

        $this->logRun($result, $started, $triggeredBy, $userId);

        // 0.8.2: pas ruajtjes së kurseve të reja të referencës, kontrollo nëse tregu ka lëvizur nga kursi i publikuar.
        if (! $dryRun && $source['driver'] !== 'boa' && in_array($result['status'], ['ok', 'partial'], true)) {
            try {
                app(MarketRateService::class)->notifyDrift();
            } catch (Throwable) {
                // njoftimi s'duhet ta prishë sinkronizimin
            }
        }

        return $result;
    }

    /** Regjistron një përpjekje (përdoret edhe nga komandat/controller-at që e kryejnë vetë leximin). */
    public function logRun(array $result, Carbon $started, string $triggeredBy, ?int $userId = null): void
    {
        try {
            FxSourceRun::query()->create([
                'source_code' => $result['code'],
                'triggered_by' => $triggeredBy,
                'user_id' => $userId,
                'dry_run' => (bool) ($result['dry_run'] ?? false),
                'status' => $result['status'],
                'data_date' => $result['data_date'] ?? null,
                'rows_read' => (int) ($result['read'] ?? 0),
                'rows_saved' => (int) ($result['saved'] ?? 0),
                'rows_unchanged' => (int) ($result['unchanged'] ?? 0),
                'rows_skipped' => count((array) ($result['skipped'] ?? [])),
                'error' => $result['error'] ?? null,
                'summary' => [
                    'rows' => $result['rows'] ?? [],
                    'skipped' => $result['skipped'] ?? [],
                    'errors' => $result['errors'] ?? [],
                ],
                'started_at' => $started,
                'finished_at' => now(),
            ]);
        } catch (Throwable) {
            // regjistrimi s'duhet ta prishë kurrë sinkronizimin (p.sh. para migrimit)
        }
    }

    /** @param array<string, mixed> $result */
    private function syncReference(array &$result, string $code, bool $dryRun): void
    {
        $fetched = $this->references->fetch($code);
        $result['data_date'] = $fetched['date']->toDateString();
        $result['errors'] = array_values((array) ($fetched['errors'] ?? []));

        $currencies = FxCurrency::query()->get()->keyBy('code');
        $official = $this->rates->officialRates();
        $threshold = (float) config('plugins.emiron-fx.general.source_outlier_percent', 15);
        $kept = [];

        foreach ($fetched['rates'] as $currencyCode => $item) {
            $currency = $currencies->get($currencyCode);

            if (! $currency) {
                continue; // monedhë që zyra s'e ka
            }

            $result['read']++;
            $unit = max(1, (int) $currency->unit);
            $row = $official->get($currency->id);
            $officialPerUnit = $row ? FxMoney::div((string) $row->rate, (string) $unit) : null;
            $deviation = null;

            if ($officialPerUnit !== null && (float) $officialPerUnit > 0) {
                $deviation = round(abs((float) $item['rate'] - (float) $officialPerUnit) / (float) $officialPerUnit * 100, 2);

                if ($deviation > $threshold) {
                    $result['skipped'][] = sprintf('%s: %.2f%% larg BSH-së (%s kundrejt %s) — hedhur', $currencyCode, $deviation, number_format((float) $item['rate'], 4, '.', ''), number_format((float) $officialPerUnit, 4, '.', ''));
                    $result['rows'][$currencyCode] = ['rate' => (string) $item['rate'], 'official' => $officialPerUnit, 'deviation' => $deviation, 'skipped' => true];

                    continue;
                }
            }

            $kept[$currencyCode] = $item;
            $result['rows'][$currencyCode] = ['rate' => (string) $item['rate'], 'official' => $officialPerUnit, 'deviation' => $deviation];
        }

        if ($result['read'] === 0) {
            throw new RuntimeException('Asnjë monedhë e zyrës s\'u përputh me kurset e lexuara.');
        }

        if (! $kept) {
            throw new RuntimeException('Të gjitha kurset u hodhën si të papërputhshme me BSH-në — burimi duhet kontrolluar.');
        }

        if (! $dryRun) {
            $recorded = $this->references->record($code, ['date' => $fetched['date'], 'rates' => $kept]);
            $result['saved'] = (int) $recorded['saved'];
            $result['unchanged'] = (int) $recorded['unchanged'];
        }
    }

    /** @param array<string, mixed> $result */
    private function syncOfficial(array &$result, bool $dryRun): void
    {
        $parsed = $this->boa->fetch();
        $result['data_date'] = $parsed['published_at']?->toDateString();
        $currencies = FxCurrency::query()->get()->keyBy('code');

        foreach ($parsed['tables'] as $table) {
            foreach ($table['rates'] as $currencyCode => $item) {
                if (! $currencies->has($currencyCode)) {
                    continue;
                }

                $result['read']++;
                $result['rows'][$currencyCode] = [
                    'rate' => FxMoney::div((string) $item['rate'], (string) max(1, (int) $item['unit'])),
                    'official' => null,
                    'deviation' => null,
                ];
            }
        }

        if ($dryRun) {
            return;
        }

        $recorded = $this->rates->recordImported($parsed);
        $result['saved'] = count($recorded['saved']);
        $result['unchanged'] = count($recorded['unchanged']);
        $result['skipped'] = array_values($recorded['skipped']);

        if (! $this->rates->importMatchedAnything($recorded)) {
            throw new RuntimeException('BSH: asnjë monedhë e zyrës s\'u përputh me kurset e lexuara.');
        }

        if ($parsed['published_at']?->isSameDay(now())) {
            $this->alerts->checkDeviation();
        }
    }

    /**
     * Pamja e përgjithshme për faqen "Burimet": çdo burim me përpjekjen e fundit, suksesin e fundit dhe shëndetin.
     *
     * @return array<int, array<string, mixed>>
     */
    public function overview(): array
    {
        $out = [];

        foreach ($this->registry->all() as $code => $source) {
            $last = FxSourceRun::query()->where('source_code', $code)->where('dry_run', false)->orderByDesc('id')->first();
            $success = FxSourceRun::query()->where('source_code', $code)->where('dry_run', false)->whereIn('status', ['ok', 'partial'])->orderByDesc('id')->first();

            $out[] = $source + [
                'last' => $last,
                'success' => $success,
                'health' => $this->health($source, $last, $success),
            ];
        }

        return $out;
    }

    /** ok | warn | bad | never | off */
    public function health(array $source, ?FxSourceRun $last, ?FxSourceRun $success): string
    {
        if (! $source['enabled']) {
            return 'off';
        }

        if (! $success) {
            return $last ? 'bad' : 'never';
        }

        $hours = $success->finished_at->diffInHours(now());

        if ($hours > 72) {
            return 'bad';
        }

        if ($hours > 36 || ($last && $last->status === 'failed' && $last->id !== $success->id)) {
            return 'warn';
        }

        return 'ok';
    }
}
