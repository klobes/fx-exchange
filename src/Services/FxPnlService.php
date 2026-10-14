<?php

namespace Botble\EmironFx\Services;

use Botble\EmironFx\Models\FxCurrency;
use Botble\EmironFx\Models\FxTillMovement;
use Botble\EmironFx\Models\FxTransaction;
use Carbon\CarbonInterface;

/**
 * Fitimi / humbja reale e këmbimit — metoda e kostos mesatare të ponderuar për monedhë.
 *
 * Stoku i valutës (në arka) ndiqet kronologjikisht mbi të gjitha lëvizjet:
 *   blerje        → stoku +, kosto + Lek-ët e paguar (kursi i blerjes);
 *   shitje        → fitim i realizuar = Lek-ët e marrë − kosto mesatare × sasia;
 *   hyrje të tjera (gjendja fillestare, furnizim, partner IN) → me kursin zyrtar BSH të ditës;
 *   dalje të tjera (derdhje, partner OUT) → me koston mesatare, pa fitim/humbje;
 *   diferenca në numërim → fitim/humbje me koston mesatare (te Leku: me vlerën nominale).
 * Çiftet e anuluara (origjinali + anulimi) përjashtohen.
 * E parealizuara = stoku × kursi BSH sot − kosto e stokut.
 */
class FxPnlService
{
    public function __construct(private readonly FxRateService $rates)
    {
    }

    /**
     * @return array{rows: array<string, array<string,string>>, totals: array<string,string>}
     */
    public function compute(CarbonInterface $from, CarbonInterface $to): array
    {
        $units = FxCurrency::query()->pluck('unit', 'code');
        $officialNow = $this->officialPerUnit(null);
        $officialCache = [];
        $state = [];
        $voided = FxTransaction::query()->whereNotNull('voids_transaction_id')->pluck('voids_transaction_id')->all();
        $voided = array_flip(array_merge($voided, FxTransaction::query()->whereNotNull('voids_transaction_id')->pluck('id')->all()));

        $fromS = $from->copy()->startOfDay();
        $toS = $to->copy()->endOfDay();
        $lekVariance = '0';

        FxTillMovement::query()->orderBy('id')->with('transaction')->chunk(2000, function ($rows) use (&$state, &$officialCache, $units, $voided, $fromS, $toS, &$lekVariance): void {
            foreach ($rows as $m) {
                $code = $m->currency_code;
                $amount = (string) $m->amount;
                $inPeriod = $m->created_at->between($fromS, $toS);

                if ($code === 'ALL') {
                    if (in_array($m->type, ['opening_variance', 'closing_variance'], true) && $inPeriod) {
                        $lekVariance = FxMoney::add($lekVariance, $amount);
                    }
                    continue;
                }

                $s = &$state[$code];
                $s ??= ['qty' => '0', 'cost' => '0', 'realized' => '0', 'variance' => '0', 'bought' => '0', 'sold' => '0'];
                $avg = FxMoney::cmp($s['qty'], '0') > 0 ? FxMoney::div($s['cost'], $s['qty']) : null;

                if (in_array($m->type, ['transaction', 'void'], true)) {
                    $tx = $m->transaction;

                    if (! $tx || isset($voided[$tx->id])) {
                        unset($s);
                        continue;
                    }

                    if ($tx->direction === FxTransaction::BUY) {
                        $s['qty'] = FxMoney::add($s['qty'], $amount);
                        $s['cost'] = FxMoney::add($s['cost'], (string) $tx->lek_amount);
                        if ($inPeriod) {
                            $s['bought'] = FxMoney::add($s['bought'], $amount);
                        }
                    } else {
                        $qty = FxMoney::neg($amount);
                        $avg ??= FxMoney::div((string) $tx->rate, (string) max(1, (int) $tx->unit));
                        $cogs = FxMoney::mul($avg, $qty);
                        $s['qty'] = FxMoney::sub($s['qty'], $qty);
                        $s['cost'] = FxMoney::sub($s['cost'], $cogs);

                        if ($inPeriod) {
                            $s['realized'] = FxMoney::add($s['realized'], FxMoney::sub((string) $tx->lek_amount, $cogs));
                            $s['sold'] = FxMoney::add($s['sold'], $qty);
                        }
                    }

                    unset($s);
                    continue;
                }

                $isVariance = in_array($m->type, ['opening_variance', 'closing_variance'], true);

                if (FxMoney::cmp($amount, '0') > 0) {
                    // Hyrje: diferencë → me koston mesatare (fitim); tjetër → me kursin BSH të ditës.
                    $date = $m->created_at->toDateString();
                    $officialCache[$code][$date] ??= $this->officialPerUnit($m->created_at)[$code] ?? null;
                    $unitCost = ($isVariance ? $avg : null) ?? $officialCache[$code][$date] ?? $avg ?? '0';
                    $value = FxMoney::mul($unitCost, $amount);
                    $s['qty'] = FxMoney::add($s['qty'], $amount);
                    $s['cost'] = FxMoney::add($s['cost'], $value);

                    if ($isVariance && $inPeriod) {
                        $s['variance'] = FxMoney::add($s['variance'], $value);
                    }
                } else {
                    $qty = FxMoney::neg($amount);
                    $value = FxMoney::mul($avg ?? '0', $qty);
                    $s['qty'] = FxMoney::sub($s['qty'], $qty);
                    $s['cost'] = FxMoney::sub($s['cost'], $value);

                    if ($isVariance && $inPeriod) {
                        $s['variance'] = FxMoney::sub($s['variance'], $value);
                    }
                }

                unset($s);
            }
        });

        $rows = [];
        $totals = ['realized' => '0', 'variance' => '0', 'unrealized' => '0', 'stock_cost' => '0', 'stock_value' => '0'];

        ksort($state);

        foreach ($state as $code => $s) {
            $value = isset($officialNow[$code]) ? FxMoney::mul($officialNow[$code], $s['qty']) : $s['cost'];
            $unrealized = FxMoney::sub($value, $s['cost']);
            $avg = FxMoney::cmp($s['qty'], '0') > 0 ? FxMoney::mul(FxMoney::div($s['cost'], $s['qty']), (string) ($units[$code] ?? 1)) : '0';

            $rows[$code] = [
                'qty' => FxMoney::round($s['qty'], 2),
                'avg' => FxMoney::round($avg, 4),
                'bought' => FxMoney::round($s['bought'], 2),
                'sold' => FxMoney::round($s['sold'], 2),
                'realized' => FxMoney::round($s['realized'], 0),
                'variance' => FxMoney::round($s['variance'], 0),
                'unrealized' => FxMoney::round($unrealized, 0),
                'stock_value' => FxMoney::round($value, 0),
            ];

            foreach (['realized', 'variance', 'unrealized'] as $k) {
                $totals[$k] = FxMoney::add($totals[$k], $rows[$code][$k]);
            }

            $totals['stock_cost'] = FxMoney::add($totals['stock_cost'], $s['cost']);
            $totals['stock_value'] = FxMoney::add($totals['stock_value'], $value);
        }

        $totals['variance'] = FxMoney::add($totals['variance'], $lekVariance);
        $totals['lek_variance'] = FxMoney::round($lekVariance, 0);
        $totals['net'] = FxMoney::round(FxMoney::add($totals['realized'], $totals['variance']), 0);

        foreach (['realized', 'variance', 'unrealized', 'stock_cost', 'stock_value'] as $k) {
            $totals[$k] = FxMoney::round($totals[$k], 0);
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /** @return array<string,string> kodi => Lek për 1 njësi (kursi BSH i ditës ose i fundit para saj) */
    private function officialPerUnit(?CarbonInterface $date): array
    {
        $out = [];
        $currencies = FxCurrency::query()->get()->keyBy('id');

        foreach ($this->rates->officialRates($date) as $currencyId => $row) {
            $c = $currencies->get($currencyId);

            if ($c) {
                $out[$c->code] = FxMoney::div((string) $row->rate, (string) max(1, (int) $c->unit));
            }
        }

        return $out;
    }
}
