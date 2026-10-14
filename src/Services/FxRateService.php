<?php

namespace Botble\EmironFx\Services;

use Botble\Base\Events\CreatedContentEvent;
use Botble\EmironFx\Models\FxCurrency;
use Botble\EmironFx\Models\FxOfficialRate;
use Botble\EmironFx\Models\FxRate;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Logjika e kurseve të zyrës së këmbimit (RULE_025: controller-i s'ka query).
 *
 * Konventa: çdo kurs është "Lek për `unit` njësi valute".
 *   buy_rate  — zyra BLEN valutën  (klienti shet valutë, merr Lek)
 *   sell_rate — zyra SHET valutën  (klienti jep Lek, merr valutë)
 * Llogaritjet me bcmath (string), asnjëherë me float.
 */
class FxRateService
{
    private int $scale;

    public function __construct()
    {
        $this->scale = (int) config('plugins.emiron-fx.general.bc_scale', 10);
    }

    /** @return Collection<int,FxCurrency> */
    public function activeCurrencies(): Collection
    {
        return FxCurrency::query()->active()->ordered()->get();
    }

    /**
     * Kursi në fuqi për çdo monedhë (rreshti i fundit i publikuar).
     *
     * @return Collection<int,FxRate> keyBy currency_id
     */
    public function currentRates(): Collection
    {
        $latestIds = FxRate::query()
            ->selectRaw('MAX(id) as id')
            ->where('effective_at', '<=', now())
            ->groupBy('currency_id')
            ->pluck('id');

        return FxRate::query()
            ->whereIn('id', $latestIds)
            ->get()
            ->keyBy('currency_id');
    }

    /**
     * Kursi i pa përditësuar: publikuar para ditës së sotme. Vetëm paralajmërim
     * (sporteli s'bllokohet); veprimi merr flamurin `stale_rate`.
     */
    public function isStale(FxRate $rate): bool
    {
        return (bool) config('plugins.emiron-fx.general.rate_stale_warning', true)
            && $rate->effective_at->lt(now()->startOfDay());
    }

    /**
     * Publikimi i mëparshëm për çdo monedhë (para atij në fuqi) — për shigjetat ▲▼.
     *
     * @param Collection<int,FxRate> $current keyBy currency_id
     * @return Collection<int,FxRate> keyBy currency_id
     */
    public function previousRates(Collection $current): Collection
    {
        return $current->map(fn (FxRate $rate) => FxRate::query()
            ->where('currency_id', $rate->currency_id)
            ->where('id', '<', $rate->id)
            ->orderByDesc('id')
            ->first())
            ->filter();
    }

    /**
     * Kursi zyrtar i BSH-së i ditës së mëparshme (para `$officialRow`) — për shigjetat.
     */
    public function previousOfficial(FxOfficialRate $officialRow): ?FxOfficialRate
    {
        return FxOfficialRate::query()
            ->where('currency_id', $officialRow->currency_id)
            ->whereDate('rate_date', '<', $officialRow->rate_date)
            ->orderByDesc('rate_date')
            ->orderByDesc('id')
            ->first();
    }

    /** -1 / 0 / 1: drejtimi i ndryshimit nga $previous te $now. */
    public function trend(?string $now, ?string $previous): int
    {
        if ($now === null || $previous === null) {
            return 0;
        }

        return bccomp($now, $previous, 6);
    }

    /**
     * Kursi zyrtar i BSH-së për datën (ose dita e fundit para saj, brenda
     * 10 ditëve — fundjavat/festat). Për një datë me korrigjim vlen rreshti i fundit.
     *
     * @return Collection<int,FxOfficialRate> keyBy currency_id
     */
    public function officialRates(?CarbonInterface $date = null): Collection
    {
        $date = ($date ?? now())->copy()->startOfDay();

        return FxOfficialRate::query()
            ->whereDate('rate_date', '<=', $date)
            ->whereDate('rate_date', '>=', $date->copy()->subDays(10))
            ->orderByDesc('rate_date')
            ->orderByDesc('id')
            ->get()
            ->unique('currency_id')
            ->keyBy('currency_id');
    }

    /** Shmangia në % e kursit nga kursi zyrtar; null pa kurs zyrtar. */
    public function deviationPercent(string $rate, ?string $official): ?string
    {
        if ($official === null || bccomp($official, '0', $this->scale) <= 0) {
            return null;
        }

        $diff = bcsub($rate, $official, $this->scale);

        return bcdiv(bcmul($diff, '100', $this->scale), $official, 4);
    }

    /**
     * Publikon një grup kursesh (një batch). Kontrollet:
     *  - blerja dhe shitja > 0, blerja < shitja;
     *  - monedha aktive;
     *  - shmangia nga kursi zyrtar ≤ pragu, përndryshe kërkohet leja
     *    fx.rates.override DHE arsyeja (note).
     *
     * @param array<int|string, array{buy?: ?string, sell?: ?string}> $rows
     * @return string batch_uuid
     */
    public function publish(array $rows, ?string $note, bool $override, bool $canOverride, ?int $userId, ?Request $request = null): string
    {
        $currencies = FxCurrency::query()->active()->get()->keyBy('id');
        $official = $this->officialRates();
        $threshold = (string) config('plugins.emiron-fx.general.deviation_threshold_percent', 3);

        $errors = [];
        $prepared = [];

        foreach ($rows as $currencyId => $row) {
            $buy = $this->normalize($row['buy'] ?? null);
            $sell = $this->normalize($row['sell'] ?? null);

            if ($buy === null && $sell === null) {
                continue;
            }

            $currency = $currencies->get((int) $currencyId);
            $key = "rows.$currencyId";

            if (! $currency) {
                $errors[$key][] = trans('plugins/emiron-fx::fx.errors.currency_inactive');
                continue;
            }

            if ($buy === null || $sell === null) {
                $errors[$key][] = trans('plugins/emiron-fx::fx.errors.both_required', ['code' => $currency->code]);
                continue;
            }

            if (bccomp($buy, '0', $this->scale) <= 0 || bccomp($sell, '0', $this->scale) <= 0) {
                $errors[$key][] = trans('plugins/emiron-fx::fx.errors.positive', ['code' => $currency->code]);
                continue;
            }

            if (bccomp($buy, $sell, $this->scale) >= 0) {
                $errors[$key][] = trans('plugins/emiron-fx::fx.errors.buy_below_sell', ['code' => $currency->code]);
                continue;
            }

            $officialRow = $official->get($currency->id);
            $officialRate = $officialRow ? (string) $officialRow->rate : null;
            $buyDev = $this->deviationPercent($buy, $officialRate);
            $sellDev = $this->deviationPercent($sell, $officialRate);

            $exceeds = collect([$buyDev, $sellDev])
                ->filter(fn ($d) => $d !== null)
                ->contains(fn ($d) => bccomp(ltrim($d, '-'), $threshold, 4) > 0);

            if ($exceeds && ! $override) {
                $errors[$key][] = trans('plugins/emiron-fx::fx.errors.deviation', [
                    'code' => $currency->code,
                    'threshold' => $threshold,
                    'buy' => $buyDev,
                    'sell' => $sellDev,
                ]);
                continue;
            }

            $prepared[] = [
                'currency' => $currency,
                'buy' => $buy,
                'sell' => $sell,
                'official' => $officialRate,
                'official_date' => $officialRow?->rate_date,
                'buy_dev' => $buyDev,
                'sell_dev' => $sellDev,
                'override' => $exceeds,
            ];
        }

        $anyOverride = collect($prepared)->contains('override', true);

        if ($anyOverride && ! $canOverride) {
            $errors['override'][] = trans('plugins/emiron-fx::fx.errors.override_forbidden');
        }

        if ($anyOverride && trim((string) $note) === '') {
            $errors['note'][] = trans('plugins/emiron-fx::fx.errors.override_note');
        }

        if (! $prepared && ! $errors) {
            $errors['rows'][] = trans('plugins/emiron-fx::fx.errors.nothing');
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $batch = (string) Str::uuid();
        $effectiveAt = now();

        DB::transaction(function () use ($prepared, $batch, $effectiveAt, $note, $userId, $request): void {
            foreach ($prepared as $item) {
                $rate = FxRate::query()->create([
                    'currency_id' => $item['currency']->id,
                    'buy_rate' => $item['buy'],
                    'sell_rate' => $item['sell'],
                    'official_rate' => $item['official'],
                    'official_rate_date' => $item['official_date'],
                    'buy_deviation_pct' => $item['buy_dev'],
                    'sell_deviation_pct' => $item['sell_dev'],
                    'is_override' => $item['override'],
                    'effective_at' => $effectiveAt,
                    'batch_uuid' => $batch,
                    'note' => $note !== null ? Str::limit(trim($note), 500, '') : null,
                    'created_by' => $userId,
                ]);

                // Gjurma te audit-log i Botble-s (RULE_031).
                event(new CreatedContentEvent('fx_rates', $request ?? request(), $rate));
            }
        });

        return $batch;
    }

    /**
     * Regjistron kursin zyrtar të BSH-së për një datë.
     *
     * @param array<int|string, ?string> $rates currency_id => rate
     */
    public function recordOfficial(CarbonInterface $date, array $rates, ?string $note, ?int $userId, ?Request $request = null): int
    {
        if ($date->copy()->startOfDay()->gt(now()->startOfDay())) {
            throw ValidationException::withMessages(['rate_date' => trans('plugins/emiron-fx::fx.errors.future_date')]);
        }

        $currencies = FxCurrency::query()->get()->keyBy('id');
        $errors = [];
        $prepared = [];

        foreach ($rates as $currencyId => $value) {
            $rate = $this->normalize($value);

            if ($rate === null) {
                continue;
            }

            $currency = $currencies->get((int) $currencyId);

            if (! $currency || bccomp($rate, '0', $this->scale) <= 0) {
                $errors["rates.$currencyId"][] = trans('plugins/emiron-fx::fx.errors.positive', ['code' => $currency?->code ?? $currencyId]);
                continue;
            }

            $prepared[] = [$currency->id, $rate];
        }

        if (! $prepared && ! $errors) {
            $errors['rates'][] = trans('plugins/emiron-fx::fx.errors.nothing');
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($prepared, $date, $note, $userId, $request): void {
            foreach ($prepared as [$currencyId, $rate]) {
                $row = FxOfficialRate::query()->create([
                    'currency_id' => $currencyId,
                    'rate_date' => $date->toDateString(),
                    'rate' => $rate,
                    'source' => 'boa_manual',
                    'note' => $note !== null ? Str::limit(trim($note), 500, '') : null,
                    'created_by' => $userId,
                ]);

                event(new CreatedContentEvent('fx_official_rates', $request ?? request(), $row));
            }
        });

        return count($prepared);
    }

    /**
     * Ruan kurset e lexuara nga faqja e BSH-së (BoaRateFetcher::fetch()).
     * Vetëm për monedhat që ekzistojnë te fx_currencies, me të njëjtën njësi.
     * I njëjti kurs për të njëjtën datë s'regjistrohet dy herë; një kurs i
     * ndryshëm për të njëjtën datë shtohet si korrigjim (rresht i ri).
     *
     * @return array{saved: array<int,string>, unchanged: array<int,string>, skipped: array<int,string>}
     */
    public function recordImported(array $parsed): array
    {
        $currencies = FxCurrency::query()->get()->keyBy('code');
        $out = ['saved' => [], 'unchanged' => [], 'skipped' => []];

        DB::transaction(function () use ($parsed, $currencies, &$out): void {
            foreach ($parsed['tables'] as $table) {
                if (! $table['date']) {
                    continue;
                }

                $date = $table['date']->format('Y-m-d');

                foreach ($table['rates'] as $code => $item) {
                    $currency = $currencies->get($code);

                    if (! $currency) {
                        continue; // monedhë që zyra s'e mban
                    }

                    if ((int) $currency->unit !== (int) $item['unit']) {
                        $out['skipped'][] = "$code: njësia BSH {$item['unit']} ≠ njësia jonë {$currency->unit}";
                        continue;
                    }

                    $existing = FxOfficialRate::query()
                        ->where('currency_id', $currency->id)
                        ->whereDate('rate_date', $date)
                        ->orderByDesc('id')
                        ->first();

                    if ($existing && bccomp((string) $existing->rate, $item['rate'], 6) === 0) {
                        $out['unchanged'][] = "$code $date";
                        continue;
                    }

                    FxOfficialRate::query()->create([
                        'currency_id' => $currency->id,
                        'rate_date' => $date,
                        'rate' => $item['rate'],
                        'source' => 'boa_import',
                        'note' => Str::limit('bankofalbania.org · ' . $table['date']->format('d.m.Y H:i:s'), 500, ''),
                        'created_by' => null,
                    ]);

                    $out['saved'][] = "$code $date = {$item['rate']}";
                }
            }
        });

        return $out;
    }

    /**
     * Importi ka vlerë vetëm nëse të paktën një monedhë e zyrës u gjet te BSH
     * (e ruajtur ose e pandryshuar). Përndryshe është dështim, jo "sukses me 0".
     */
    public function importMatchedAnything(array $result): bool
    {
        return count($result['saved']) + count($result['unchanged']) > 0;
    }

    /**
     * Sugjerim për kursin e ri që ruan marzhet e publikimit të fundit
     * kundrejt kursit zyrtar: blerja_re = zyrtari_ri × (1 + shmangia_e_fundit_blerje%).
     * Vetëm sugjerim (në panel dhe në email) — s'publikohet kurrë vetë.
     *
     * @return array{buy: string, sell: string}|null
     */
    public function suggestion(?FxRate $current, ?string $newOfficial): ?array
    {
        if (! $current || $newOfficial === null || $current->buy_deviation_pct === null || $current->sell_deviation_pct === null) {
            return null;
        }

        $factor = fn (string $pct) => bcadd('1', bcdiv($pct, '100', $this->scale), $this->scale);

        $buy = bcmul($newOfficial, $factor((string) $current->buy_deviation_pct), $this->scale);
        $sell = bcmul($newOfficial, $factor((string) $current->sell_deviation_pct), $this->scale);

        return [
            'buy' => number_format((float) $buy, 2, '.', ''),
            'sell' => number_format((float) $sell, 2, '.', ''),
        ];
    }

    /**
     * Konvertim me kurset në fuqi (përdoret nga konvertuesi publik dhe, më vonë,
     * nga regjistri i veprimeve). ALL = Lek.
     *   X → ALL : shuma × blerja_X ÷ njësia_X
     *   ALL → Y : shuma ÷ (shitja_Y ÷ njësia_Y)
     *   X → Y   : përmes Lek-ut, me të dyja kurset
     */
    public function convert(string $amount, string $from, string $to): ?string
    {
        $amount = $this->normalize($amount);

        if ($amount === null) {
            return null;
        }

        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return $amount;
        }

        $currencies = FxCurrency::query()->active()->get()->keyBy('code');
        $rates = $this->currentRates();

        $lek = $amount;

        if ($from !== 'ALL') {
            $c = $currencies->get($from);
            $r = $c ? $rates->get($c->id) : null;

            if (! $r) {
                return null;
            }

            $lek = bcdiv(bcmul($amount, (string) $r->buy_rate, $this->scale), (string) $c->unit, $this->scale);
        }

        if ($to === 'ALL') {
            return $lek;
        }

        $c = $currencies->get($to);
        $r = $c ? $rates->get($c->id) : null;

        if (! $r) {
            return null;
        }

        $perUnit = bcdiv((string) $r->sell_rate, (string) $c->unit, $this->scale);

        return bcdiv($lek, $perUnit, $this->scale);
    }

    /**
     * Të dhënat për tabelën publike: vetëm monedhat aktive me kurs në fuqi.
     *
     * @return array{updated_at: ?\Carbon\CarbonInterface, rows: array<int,array<string,mixed>>}
     */
    public function publicBoard(): array
    {
        $rates = $this->currentRates();
        $previous = $this->previousRates($rates);
        $official = $this->officialRates();
        $rows = [];
        $updatedAt = null;

        foreach ($this->activeCurrencies() as $currency) {
            $rate = $rates->get($currency->id);

            if (! $rate) {
                continue;
            }

            $updatedAt = $updatedAt === null || $rate->effective_at->gt($updatedAt) ? $rate->effective_at : $updatedAt;
            $officialRow = $official->get($currency->id);
            $prev = $previous->get($currency->id);
            $prevOfficial = $officialRow ? $this->previousOfficial($officialRow) : null;

            $rows[] = [
                'code' => $currency->code,
                'name' => $currency->name,
                'symbol' => $currency->symbol,
                'unit' => $currency->unit,
                'decimals' => $currency->decimals,
                'buy' => (string) $rate->buy_rate,
                'sell' => (string) $rate->sell_rate,
                'official' => $officialRow ? (string) $officialRow->rate : null,
                'official_date' => $officialRow?->rate_date,
                'buy_prev' => $prev ? (string) $prev->buy_rate : null,
                'sell_prev' => $prev ? (string) $prev->sell_rate : null,
                'official_prev' => $prevOfficial ? (string) $prevOfficial->rate : null,
                'buy_trend' => $this->trend((string) $rate->buy_rate, $prev ? (string) $prev->buy_rate : null),
                'sell_trend' => $this->trend((string) $rate->sell_rate, $prev ? (string) $prev->sell_rate : null),
                'official_trend' => $officialRow ? $this->trend((string) $officialRow->rate, $prevOfficial ? (string) $prevOfficial->rate : null) : 0,
            ];
        }

        return ['updated_at' => $updatedAt, 'rows' => $rows];
    }

    /** "98,15" / " 98.15 " → "98.15"; bosh → null; jo numër → null. */
    public function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace([' ', "\u{00A0}"], '', trim((string) $value));
        $value = str_replace(',', '.', $value);

        if ($value === '' || ! preg_match('/^\d+(\.\d+)?$/', $value)) {
            return null;
        }

        return $value;
    }
}
