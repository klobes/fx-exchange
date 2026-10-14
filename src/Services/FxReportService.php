<?php

namespace Botble\EmironFx\Services;

use Botble\EmironFx\Models\FxAccessLog;
use Botble\EmironFx\Models\FxCurrency;
use Botble\EmironFx\Models\FxReport;
use Botble\EmironFx\Models\FxTillSession;
use Botble\EmironFx\Models\FxTransaction;
use Botble\EmironFx\Reports\BoaWorkbookWriter;
use Botble\EmironFx\Support\FxCrypto;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Faza 2c — raportet.
 *
 * BSH (libri "Statistika Monetare Ditore", i konsoliduar për të gjitha zyrat):
 *   F1.1 = zyra blen valutë (spot valutë→Lek), F1.2 = zyra shet valutë.
 *   Rresht "Individual": klient me total ditor në atë drejtim ≥ 50 000 EUR
 *   (ekuivalent me kursin zyrtar), një rresht për klient × monedhë; emri vetëm
 *   kur klienti s'është "Individë". Të tjerat: "I agreguar", një rresht për
 *   kategori × monedhë. Kursi = mesatarja e ponderuar (ΣLek ÷ Σvalutë × njësia).
 *   F3 = për monedhë: totali i blerë dhe i shitur gjatë ditës (lëvizjet e ditës).
 *
 * AIF: klientët me cash ≥ 1 000 000 Lek brenda çdo dritareje 24-orëshe që
 *   përfshin ditën e raportit — lista për futjen manuale online (CSV).
 *
 * Përjashtohen gjithmonë çiftet e anuluara (origjinali + anulimi).
 * Raporti gjenerohet vetëm kur asnjë seancë me veprime të asaj dite s'është e hapur.
 */
class FxReportService
{
    public const BOA = 'boa_daily';
    public const AIF = 'aif_ctr';

    public function __construct(private readonly BoaWorkbookWriter $writer)
    {
    }

    /** Veprimet e vlefshme të ditës (pa anulime dhe pa të anuluarat). */
    public function validTransactions(CarbonInterface $date): Collection
    {
        return FxTransaction::query()
            ->whereDate('business_date', $date->toDateString())
            ->whereNull('voids_transaction_id')
            ->whereDoesntHave('voidedBy')
            ->with('customer')
            ->orderBy('id')
            ->get();
    }

    public function assertDayClosed(CarbonInterface $date): void
    {
        $openSessions = FxTillSession::query()
            ->where('status', FxTillSession::STATUS_OPEN)
            ->whereHas('transactions', fn ($q) => $q->whereDate('business_date', $date->toDateString()))
            ->count();

        if ($openSessions > 0) {
            throw ValidationException::withMessages(['report_date' => trans('plugins/emiron-fx::fx.reports.errors.day_open', ['count' => $openSessions])]);
        }
    }

    /**
     * @return array{F1.1: array, F1.2: array, F3: array, summary: array}
     */
    public function boaData(CarbonInterface $date): array
    {
        $txs = $this->validTransactions($date);
        $units = $this->units();
        $threshold = (string) config('plugins.emiron-fx.general.boa_individual_eur', '50000');
        $categoryOrder = array_flip((array) config('plugins.emiron-fx.general.client_categories', []));
        $out = ['summary' => ['transactions' => $txs->count()]];

        foreach (['F1.1' => FxTransaction::BUY, 'F1.2' => FxTransaction::SELL] as $sheet => $direction) {
            $rows = $txs->where('direction', $direction);

            $qualifying = $rows->whereNotNull('customer_id')
                ->groupBy('customer_id')
                ->filter(fn (Collection $g) => FxMoney::cmp($this->sum($g, 'eur_equivalent'), $threshold) >= 0)
                ->keys()
                ->all();

            $groups = ['individual' => [], 'aggregated' => []];

            foreach ($rows as $tx) {
                $isIndividual = $tx->customer_id && in_array($tx->customer_id, $qualifying, false);
                $key = $isIndividual
                    ? 'I|' . $tx->customer_id . '|' . $tx->currency_code
                    : 'A|' . $tx->client_category . '|' . $tx->currency_code;
                $bucket = $isIndividual ? 'individual' : 'aggregated';

                $groups[$bucket][$key] ??= [
                    'category' => $tx->client_category,
                    'type' => $isIndividual ? 'Individual' : 'I agreguar',
                    // BSH kërkon "te ndryshem" te fusha e emrit për rreshtat e agreguar (0.5.4, i verifikuar me portalin e BSH-së).
                    // Për rreshtat individualë emri jepet vetëm për jo-individët; individët mbeten bosh.
                    'name' => $isIndividual
                        ? ($tx->client_category !== 'Individë' ? ($tx->customer?->displayName() ?? '') : '')
                        : 'te ndryshem',
                    'currency' => $tx->currency_code,
                    'foreign' => '0',
                    'lek' => '0',
                ];
                $groups[$bucket][$key]['foreign'] = FxMoney::add($groups[$bucket][$key]['foreign'], (string) $tx->foreign_amount);
                $groups[$bucket][$key]['lek'] = FxMoney::add($groups[$bucket][$key]['lek'], (string) $tx->lek_amount);
            }

            $sort = fn (array $a, array $b) => [($categoryOrder[$a['category']] ?? 99), $a['currency'], $a['name']]
                <=> [($categoryOrder[$b['category']] ?? 99), $b['currency'], $b['name']];
            $individual = array_values($groups['individual']);
            $aggregated = array_values($groups['aggregated']);
            usort($individual, $sort);
            usort($aggregated, $sort);

            $out[$sheet] = array_map(function (array $r) use ($units) {
                $rate = FxMoney::mul(FxMoney::div($r['lek'], $r['foreign']), (string) ($units[$r['currency']] ?? 1));

                return [
                    'A' => $r['category'],
                    'B' => $r['type'],
                    'C' => $r['name'] ?: null,
                    'D' => $r['currency'],
                    'E' => FxMoney::round($r['foreign'], 2),
                    'F' => FxMoney::round($rate, 4),
                ];
            }, array_merge($individual, $aggregated));

            $out['summary'][$sheet] = ['rows' => count($out[$sheet]), 'individual' => count($individual)];
        }

        $positions = [];

        foreach ($txs as $tx) {
            $positions[$tx->currency_code] ??= ['bought' => '0', 'sold' => '0'];
            $side = $tx->direction === FxTransaction::BUY ? 'bought' : 'sold';
            $positions[$tx->currency_code][$side] = FxMoney::add($positions[$tx->currency_code][$side], (string) $tx->foreign_amount);
        }

        ksort($positions);
        $out['F3'] = [];

        foreach ($positions as $code => $p) {
            $out['F3'][] = ['A' => $code, 'B' => FxMoney::round($p['bought'], 2), 'C' => FxMoney::round($p['sold'], 2)];
        }

        return $out;
    }

    /**
     * Klientët për raportim te AIF-i: cash ≥ pragut në një dritare 24-orëshe që
     * përfshin të paktën një veprim të ditës së raportit.
     *
     * @return array<int, array{customer: \Botble\EmironFx\Models\FxCustomer, transactions: Collection, total: string}>
     */
    public function aifData(CarbonInterface $date): array
    {
        $threshold = (string) config('plugins.emiron-fx.general.cdd_threshold_lek', '1000000');
        $from = $date->copy()->startOfDay()->subDay();
        $to = $date->copy()->endOfDay();

        // 0.8.0: këmbisa 2 e kryqëzimit s'numërohet (vlera në Lek numërohet një herë, te këmbisa 1).
        $byCustomer = FxTransaction::query()
            ->notSecondLeg()
            ->whereNotNull('customer_id')
            ->whereBetween('occurred_at', [$from, $to])
            ->whereNull('voids_transaction_id')
            ->whereDoesntHave('voidedBy')
            ->with('customer')
            ->orderBy('occurred_at')
            ->get()
            ->groupBy('customer_id');

        $result = [];

        foreach ($byCustomer as $txs) {
            $hit = collect();

            foreach ($txs as $i => $tx) {
                $window = $txs->filter(fn ($t) => $t->occurred_at->between($tx->occurred_at->copy()->subDay(), $tx->occurred_at));

                if (FxMoney::cmp($this->sum($window, 'lek_amount'), $threshold) >= 0
                    && $window->contains(fn ($t) => $t->business_date->isSameDay($date))) {
                    $hit = $hit->merge($window);
                }
            }

            $hit = $hit->unique('id')->sortBy('occurred_at')->values();

            if ($hit->isNotEmpty()) {
                $result[] = ['customer' => $txs->first()->customer, 'transactions' => $hit, 'total' => FxMoney::round($this->sum($hit, 'lek_amount'), 2)];
            }
        }

        return $result;
    }

    public function generate(string $type, CarbonInterface $date, ?int $userId): FxReport
    {
        $this->assertDayClosed($date);

        $dir = trim((string) config('plugins.emiron-fx.general.reports_disk_path', 'fx-reports'), '/') . '/' . $type . '/' . $date->format('Y');
        $version = (int) FxReport::query()->where('type', $type)->whereDate('report_date', $date)->max('version') + 1;
        $disk = Storage::disk('local');
        $disk->makeDirectory($dir);

        if ($type === self::BOA) {
            $data = $this->boaData($date);
            $file = sprintf('%s/BSH_%s_v%d.xlsx', $dir, $date->format('Ymd'), $version);
            $template = plugin_path('emiron-fx/resources/' . config('plugins.emiron-fx.general.boa_report_template'));
            $this->writer->write($template, $disk->path($file), $date, ['F1.1' => $data['F1.1'], 'F1.2' => $data['F1.2'], 'F3' => $data['F3']]);
            $summary = $data['summary'];
        } else {
            $data = $this->aifData($date);
            // Përmban emra dhe dokumente: ruhet i enkriptuar me FX_DATA_KEY, dekriptohet vetëm në shkarkim.
            $file = sprintf('%s/AIF_%s_v%d.csv.enc', $dir, $date->format('Ymd'), $version);
            $disk->put($file, (string) FxCrypto::encrypt($this->aifCsv($data)));
            $summary = ['customers' => count($data), 'transactions' => array_sum(array_map(fn ($d) => $d['transactions']->count(), $data))];
        }

        $report = FxReport::query()->create([
            'type' => $type,
            'report_date' => $date->toDateString(),
            'version' => $version,
            'file_path' => $file,
            'checksum' => hash_file('sha256', $disk->path($file)),
            'summary' => $summary,
            'generated_by' => $userId,
            'generated_at' => now(),
        ]);

        FxAccessLog::record('report', 'report', $report->id, $type . ' ' . $date->toDateString());

        return $report;
    }

    public function markSubmitted(FxReport $report, int $userId, ?string $reference): FxReport
    {
        if ($report->submitted_at) {
            throw ValidationException::withMessages(['submission_ref' => trans('plugins/emiron-fx::fx.reports.errors.already_submitted')]);
        }

        $report->forceFill(['submitted_at' => now(), 'submitted_by' => $userId, 'submission_ref' => $reference])->save();

        return $report;
    }

    /** Afati i BSH-së: ora 12:00 e ditës pasardhëse të punës (pa fundjavat dhe festat zyrtare). */
    public function deadline(CarbonInterface $date): CarbonInterface
    {
        [$h, $m] = array_map('intval', explode(':', (string) config('plugins.emiron-fx.general.boa_report_deadline', '12:00')));

        return app(FxCalendar::class)->nextBusinessDay($date)->setTime($h, $m);
    }

    protected function units(): Collection
    {
        return FxCurrency::query()->pluck('unit', 'code');
    }

    private function aifCsv(array $data): string
    {
        $lines = [[
            trans('plugins/emiron-fx::fx.reports.csv.customer'), trans('plugins/emiron-fx::fx.reports.csv.document'),
            trans('plugins/emiron-fx::fx.reports.csv.category'), trans('plugins/emiron-fx::fx.reports.csv.number'),
            trans('plugins/emiron-fx::fx.reports.csv.time'), trans('plugins/emiron-fx::fx.reports.csv.direction'),
            trans('plugins/emiron-fx::fx.reports.csv.currency'), trans('plugins/emiron-fx::fx.reports.csv.amount'),
            trans('plugins/emiron-fx::fx.reports.csv.rate'), 'Lek', trans('plugins/emiron-fx::fx.reports.csv.total'),
        ]];

        foreach ($data as $row) {
            $c = $row['customer'];

            foreach ($row['transactions'] as $tx) {
                $lines[] = [
                    $c?->displayName(), trim(($c?->doc_type ?? '') . ' ' . ($c?->doc_number ?? '')), $tx->client_category,
                    $tx->number, $tx->occurred_at->format('d.m.Y H:i:s'), $tx->direction, $tx->currency_code,
                    (string) $tx->foreign_amount, (string) $tx->rate, (string) $tx->lek_amount, $row['total'],
                ];
            }
        }

        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");

        foreach ($lines as $line) {
            fputcsv($fh, $line, ';');
        }

        rewind($fh);

        return (string) stream_get_contents($fh);
    }

    private function sum(Collection $rows, string $field): string
    {
        return $rows->reduce(fn (string $carry, $t) => FxMoney::add($carry, (string) ($t->{$field} ?? '0')), '0');
    }
}
