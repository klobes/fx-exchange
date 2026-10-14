<?php

namespace Botble\EmironFx\Services;

use Botble\EmironFx\Models\FxCurrency;
use Botble\EmironFx\Models\FxTillMovement;
use Illuminate\Support\Collection;

/**
 * Gjendja cash e subjektit dhe kufiri i saj (holding_limit_lek, sipas kapitalit).
 * Vlerësimi: Lek me vlerë nominale; valuta me kursin zyrtar të BSH-së
 * (i fundit brenda 10 ditëve); pa kurs zyrtar → kursi i blerjes së zyrës në fuqi.
 */
class FxHoldingService
{
    public function __construct(private readonly FxRateService $rates)
    {
    }

    /** @return array<string,string> kodi => gjendja, për të gjitha arkat (ose një arkë) */
    public function balances(?int $tillId = null): array
    {
        return FxTillMovement::query()
            ->when($tillId, fn ($q) => $q->where('till_id', $tillId))
            ->selectRaw('currency_code, SUM(amount) as total')
            ->groupBy('currency_code')
            ->pluck('total', 'currency_code')
            ->map(fn ($v) => FxMoney::clean((string) $v))
            ->all();
    }

    /** Lek për 1 njësi të monedhës (për vlerësim). ALL = 1. null = s'ka kurs. */
    public function valuationRates(): Collection
    {
        $official = $this->rates->officialRates();
        $current = $this->rates->currentRates();

        return FxCurrency::query()->get()->mapWithKeys(function (FxCurrency $c) use ($official, $current) {
            $rate = $official->get($c->id)?->rate ?? $current->get($c->id)?->buy_rate;

            return [$c->code => $rate === null ? null : FxMoney::div((string) $rate, (string) max(1, $c->unit))];
        })->put('ALL', '1');
    }

    /** Vlera në Lek e një grupi gjendjesh. */
    public function valueLek(array $balances, ?Collection $rates = null): string
    {
        $rates ??= $this->valuationRates();
        $total = '0';

        foreach ($balances as $code => $amount) {
            $perUnit = $rates->get($code);

            if ($perUnit !== null) {
                $total = FxMoney::add($total, FxMoney::mul((string) $amount, $perUnit));
            }
        }

        return $total;
    }

    /**
     * Gjendja e subjektit pas një ndryshimi (delta: kodi => shuma e nënshkruar).
     *
     * @return array{before: string, after: string, limit: string, percent: float, exceeds: bool, warn: bool}
     */
    public function check(array $delta = []): array
    {
        $rates = $this->valuationRates();
        $balances = $this->balances();
        $before = $this->valueLek($balances, $rates);

        foreach ($delta as $code => $amount) {
            $balances[$code] = FxMoney::add($balances[$code] ?? '0', (string) $amount);
        }

        $after = $this->valueLek($balances, $rates);
        $limit = (string) config('plugins.emiron-fx.general.holding_limit_lek', '5000000');
        $percent = FxMoney::cmp($limit, '0') > 0 ? (float) FxMoney::div(FxMoney::mul($after, '100'), $limit) : 0.0;

        return [
            'before' => FxMoney::round($before, 0),
            'after' => FxMoney::round($after, 0),
            'limit' => $limit,
            'percent' => round($percent, 1),
            // Kufiri është "më e vogël se": e barabartë me kufirin = e tejkaluar.
            'exceeds' => FxMoney::cmp($after, $limit) >= 0,
            'warn' => $percent >= (float) config('plugins.emiron-fx.general.holding_warn_percent', 90),
        ];
    }
}
