<?php

namespace Botble\EmironFx\Services;

use Botble\EmironFx\Models\FxCurrency;
use Botble\EmironFx\Models\FxRate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Kursi i rekomanduar nga tregu (0.8.2).
 *
 *  1. MID i tregut për monedhë = mediana e burimeve të sheshuara "në mesatare" (parazgjedhur vetëm Wise), pas hedhjes së
 *     burimeve të vjetra dhe të outlier-ave (≥ 3 burime: larg medianës mbi `market_outlier_percent`).
 *  2. Kursi i rekomanduar i zyrës = MID ± marzhi, sipas mënyrës (e zgjedhshme):
 *       last    — marzhet e publikimit të fundit kundrejt BSH-së, të zbatuara mbi MID-in e tregut;
 *       percent — blerja = MID × (1 − b%), shitja = MID × (1 + s%) (për monedhë);
 *       lek     — blerja = MID − b Lek, shitja = MID + s Lek (për monedhë).
 *  3. BSH mbetet referenca e kontrollit (shmangia kundrejt zyrtarit); nuk hyn në mesatare.
 *
 * Vetëm sugjerim: asnjë kurs s'publikohet vetë. Kur MID largohet nga kursi i publikuar mbi pragun → banner + email.
 */
class MarketRateService
{
    public const MODES = ['last', 'percent', 'lek'];

    public function __construct(
        private readonly ReferenceRateService $references,
        private readonly SourceRegistry $registry,
        private readonly FxRateService $rates,
    ) {
    }

    // ───────────── Cilësimet ─────────────

    public function setting(string $key, ?string $default = null): ?string
    {
        try {
            $value = DB::table('fx_market_settings')->where('key', $key)->value('value');
        } catch (Throwable) {
            $value = null;
        }

        return $value !== null ? (string) $value : $default;
    }

    public function putSetting(string $key, ?string $value): void
    {
        DB::table('fx_market_settings')->updateOrInsert(['key' => $key], ['value' => $value, 'updated_at' => now(), 'created_at' => now()]);
    }

    public function marginMode(): string
    {
        $mode = (string) $this->setting('margin_mode', (string) config('plugins.emiron-fx.general.market_margin_mode', 'last'));

        return in_array($mode, self::MODES, true) ? $mode : 'last';
    }

    /**
     * Marzhi i përgjithshëm në Lek (0.8.4): blerja = MID − b, shitja = MID + s, për çdo monedhë pa marzhe të veta.
     * Parazgjedhje 0.50 / 0.50 (config market_default_margin_lek); cilësimet default_buy_lek / default_sell_lek e ndryshojnë.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function defaultMargins(): array
    {
        $fallback = (string) config('plugins.emiron-fx.general.market_default_margin_lek', '0.5');
        $buy = $this->setting('default_buy_lek');
        $sell = $this->setting('default_sell_lek');

        $buy = ($buy !== null && $buy !== '' && is_numeric($buy)) ? $buy : $fallback;
        $sell = ($sell !== null && $sell !== '' && is_numeric($sell)) ? $sell : $fallback;

        return [is_numeric($buy) ? (string) $buy : null, is_numeric($sell) ? (string) $sell : null];
    }

    public function driftThreshold(): float
    {
        return (float) $this->setting('drift_threshold', (string) config('plugins.emiron-fx.general.market_drift_alert_percent', 0.5));
    }

    public function minSources(): int
    {
        return max(1, (int) $this->setting('min_sources', (string) config('plugins.emiron-fx.general.market_min_sources', 1)));
    }

    /** Kodet e burimeve që hyjnë në mesatare: të aktivizuara, jo zyrtare, të sheshuara. @return array<int, string> */
    public function averagingCodes(): array
    {
        return $this->registry->all()
            ->filter(fn (array $s) => $s['role'] !== 'official' && $s['enabled'] && ! empty($s['use_in_average']))
            ->keys()
            ->values()
            ->all();
    }

    /** @return array<int, array{buy_pct: ?string, sell_pct: ?string, buy_lek: ?string, sell_lek: ?string}> currency_id => marzhet */
    public function margins(): array
    {
        try {
            return DB::table('fx_market_margins')->get()->mapWithKeys(fn ($r) => [(int) $r->currency_id => [
                'buy_pct' => $r->buy_pct, 'sell_pct' => $r->sell_pct, 'buy_lek' => $r->buy_lek, 'sell_lek' => $r->sell_lek,
            ]])->all();
        } catch (Throwable) {
            return [];
        }
    }

    // ───────────── MID i tregut ─────────────

    /**
     * @return Collection<int, array{
     *   mid: ?string, count: int, method: string, sources: array<string,string>, excluded: array<string,string>,
     *   official: ?string, deviation: ?float, date: ?string, reason: ?string
     * }> currency_id => rezultati
     */
    public function consensus(): Collection
    {
        $codes = $this->averagingCodes();
        $latest = $this->references->latest();
        $official = $this->rates->officialRates();
        $maxAgeDays = (int) config('plugins.emiron-fx.general.market_max_age_days', 2);
        $outlier = (float) config('plugins.emiron-fx.general.market_outlier_percent', 1.0);
        $min = $this->minSources();
        $oldest = now()->startOfDay()->subDays($maxAgeDays);
        $out = collect();

        foreach ($this->rates->activeCurrencies() as $currency) {
            $values = [];
            $excluded = [];
            $dates = [];

            foreach ($codes as $code) {
                $row = $latest->get($code)?->get($currency->id);

                if (! $row) {
                    continue;
                }

                if ($row->rate_date->lt($oldest)) {
                    $excluded[$code] = 'i vjetër (' . $row->rate_date->format('d.m.Y') . ')';

                    continue;
                }

                $values[$code] = (string) $row->rate;
                $dates[] = $row->rate_date;
            }

            if (count($values) >= 3) {
                $median = $this->median(array_values($values));

                foreach ($values as $code => $value) {
                    if (abs($this->pct($value, $median)) > $outlier) {
                        $excluded[$code] = sprintf('larg medianës (%+.2f%%)', $this->pct($value, $median));
                        unset($values[$code]);
                    }
                }
            }

            $off = $official->get($currency->id);
            $offRate = $off ? (string) $off->rate : null;

            if (count($values) < $min) {
                $out->put($currency->id, [
                    'mid' => null, 'count' => count($values), 'method' => 'none', 'sources' => $values, 'excluded' => $excluded,
                    'official' => $offRate, 'deviation' => null, 'date' => null,
                    'reason' => count($values) === 0 ? 'no_data' : 'insufficient',
                ]);

                continue;
            }

            $mid = $this->median(array_values($values));

            $out->put($currency->id, [
                'mid' => $mid,
                'count' => count($values),
                'method' => count($values) === 1 ? 'single' : 'median',
                'sources' => $values,
                'excluded' => $excluded,
                'official' => $offRate,
                'deviation' => $offRate !== null ? (float) $this->rates->deviationPercent($mid, $offRate) : null,
                'date' => collect($dates)->max()?->toDateString(),
                'reason' => null,
            ]);
        }

        return $out;
    }

    // ───────────── Rekomandimi ─────────────

    /**
     * @return array{buy: string, sell: string, mode: string}|null  null = s'ka bazë për llogaritje
     */
    public function recommend(FxCurrency $currency, ?string $mid, ?FxRate $current): ?array
    {
        if ($mid === null) {
            return null;
        }

        $mode = $this->marginMode();
        $margins = $this->margins()[$currency->id] ?? null;
        $buy = $sell = null;

        if ($mode === 'last' && $current && $current->buy_deviation_pct !== null && $current->sell_deviation_pct !== null) {
            $buy = bcmul($mid, bcadd('1', bcdiv((string) $current->buy_deviation_pct, '100', 10), 10), 10);
            $sell = bcmul($mid, bcadd('1', bcdiv((string) $current->sell_deviation_pct, '100', 10), 10), 10);
        } elseif ($mode === 'percent' && $margins && $margins['buy_pct'] !== null && $margins['sell_pct'] !== null) {
            $buy = bcmul($mid, bcsub('1', bcdiv((string) $margins['buy_pct'], '100', 10), 10), 10);
            $sell = bcmul($mid, bcadd('1', bcdiv((string) $margins['sell_pct'], '100', 10), 10), 10);
        } elseif ($mode === 'lek' && $margins && $margins['buy_lek'] !== null && $margins['sell_lek'] !== null) {
            $buy = bcsub($mid, (string) $margins['buy_lek'], 10);
            $sell = bcadd($mid, (string) $margins['sell_lek'], 10);
        }

        // 0.8.4: mbrojtja nga lëvizja e kursit — kur mënyra e zgjedhur s'ka bazë (p.sh. s'ka publikim të mëparshëm
        // ose marzhe për monedhën), zbatohet marzhi i përgjithshëm në Lek: blerja = MID − b, shitja = MID + s.
        if ($buy === null || $sell === null) {
            [$defBuy, $defSell] = $this->defaultMargins();

            if ($defBuy === null || $defSell === null) {
                return null;
            }

            $buy = bcsub($mid, $defBuy, 10);
            $sell = bcadd($mid, $defSell, 10);
            $mode = 'default';
        }

        $decimals = (int) config('plugins.emiron-fx.general.market_decimals', 2);
        $buy = number_format((float) $buy, $decimals, '.', '');
        $sell = number_format((float) $sell, $decimals, '.', '');

        if ((float) $buy <= 0 || (float) $buy >= (float) $sell) {
            return null; // marzhe të pavlefshme (blerja duhet < shitja)
        }

        return ['buy' => $buy, 'sell' => $sell, 'mode' => $mode];
    }

    /**
     * Rreshtat e panelit: për çdo monedhë aktive — MID, rekomandimi, kursi i publikuar, lëvizja.
     *
     * @return array<int, array<string, mixed>>
     */
    public function overview(): array
    {
        $consensus = $this->consensus();
        $current = $this->rates->currentRates();
        $rows = [];

        foreach ($this->rates->activeCurrencies() as $currency) {
            $c = $consensus->get($currency->id);
            $cur = $current->get($currency->id);
            $mid = $c['mid'] ?? null;
            $drift = null;

            if ($mid !== null && $cur) {
                $officeMid = ((float) $cur->buy_rate + (float) $cur->sell_rate) / 2;
                $drift = $officeMid > 0 ? round(((float) $mid - $officeMid) / $officeMid * 100, 2) : null;
            }

            $rows[] = [
                'currency' => $currency,
                'consensus' => $c,
                'current' => $cur,
                'recommended' => $this->recommend($currency, $mid, $cur),
                'drift' => $drift,
            ];
        }

        return $rows;
    }

    /** Monedhat ku MID i tregut ka lëvizur nga kursi i publikuar mbi prag. @return array<int, array<string, mixed>> */
    public function drifting(): array
    {
        $threshold = $this->driftThreshold();

        return array_values(array_filter(
            $this->overview(),
            fn (array $row) => $row['drift'] !== null && abs($row['drift']) >= $threshold
        ));
    }

    /** Dërgon njoftimin (një herë për gjendje/ditë) nëse ka lëvizje mbi prag. @return array<int, string> rreshtat */
    public function notifyDrift(): array
    {
        $drifting = $this->drifting();

        if (! $drifting) {
            return [];
        }

        $lines = [];
        $fingerprint = [];

        foreach ($drifting as $row) {
            /** @var FxCurrency $currency */
            $currency = $row['currency'];
            $c = $row['consensus'];
            $cur = $row['current'];
            $rec = $row['recommended'];

            $line = sprintf(
                '%-4s tregu %s (%d burim%s) | zyra: blerje %s / shitje %s | lëvizje %+.2f%%',
                $currency->code,
                fx_rate($c['mid']),
                $c['count'],
                $c['count'] === 1 ? '' : 'e',
                fx_rate($cur->buy_rate),
                fx_rate($cur->sell_rate),
                $row['drift']
            );

            if ($rec) {
                $line .= sprintf(' | rekomandim: %s / %s', $rec['buy'], $rec['sell']);
            }

            if ($c['deviation'] !== null) {
                $line .= sprintf(' | vs BSH %+.2f%%', $c['deviation']);
            }

            $lines[] = $line;
            $fingerprint[] = $currency->code . ':' . number_format((float) $c['mid'], 2, '.', '') . ':' . $cur->batch_uuid;
        }

        app(FxAlertService::class)->marketMoved($lines, implode('|', $fingerprint));

        return $lines;
    }

    // ───────────── Ndihmëse ─────────────

    /** @param array<int, string> $values */
    private function median(array $values): string
    {
        usort($values, fn (string $a, string $b) => bccomp($a, $b, 10));
        $n = count($values);
        $mid = intdiv($n, 2);

        if ($n % 2 === 1) {
            return $values[$mid];
        }

        return bcdiv(bcadd($values[$mid - 1], $values[$mid], 10), '2', 6);
    }

    private function pct(string $value, string $base): float
    {
        return (float) $base > 0 ? ((float) $value - (float) $base) / (float) $base * 100 : 0.0;
    }
}
