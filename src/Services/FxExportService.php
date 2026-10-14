<?php

namespace Botble\EmironFx\Services;

use Botble\EmironFx\Models\FxTransaction;
use Carbon\CarbonInterface;

/**
 * Eksporti mujor për kontabilistin (CSV, UTF-8 me BOM, ndarës ";" — hapet drejt në Excel).
 * Të gjitha veprimet e muajit, përfshirë anulimet (të shënuara), pa të dhëna personale
 * të klientit; në fund: përmbledhja për monedhë (vetëm veprimet e vlefshme).
 */
class FxExportService
{
    public function monthCsv(CarbonInterface $month): string
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();

        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        $t = fn (string $k) => trans('plugins/emiron-fx::fx.export.' . $k);

        fputcsv($fh, [$t('date'), $t('time'), $t('number'), $t('office'), $t('till'), $t('cashier'), $t('direction'), $t('currency'),
            $t('amount'), $t('rate'), $t('unit'), 'Lek', $t('official'), $t('category'), $t('status'), $t('reference')], ';');

        $summary = [];

        FxTransaction::query()
            ->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])
            ->with(['office', 'till', 'cashier', 'voidedBy', 'voids'])
            ->orderBy('office_id')->orderBy('seq')
            ->chunk(1000, function ($rows) use ($fh, $t, &$summary): void {
                foreach ($rows as $tx) {
                    $status = $tx->isReversal() ? $t('status_reversal') : ($tx->voidedBy ? $t('status_voided') : $t('status_valid'));

                    fputcsv($fh, [
                        $tx->business_date->format('d.m.Y'), $tx->occurred_at->format('H:i:s'), $tx->number, $tx->office?->code,
                        $tx->till?->name, $tx->cashier?->name, $tx->direction === 'buy' ? $t('buy') : $t('sell'), $tx->currency_code,
                        $this->num($tx->foreign_amount), $this->num($tx->rate, 4), $tx->unit, $this->num($tx->lek_amount),
                        $tx->official_rate !== null ? $this->num($tx->official_rate, 4) : '', $tx->client_category, $status,
                        $tx->isReversal() ? $tx->voids?->number : ($tx->voidedBy?->number ?? ''),
                    ], ';');

                    if (! $tx->isReversal() && ! $tx->voidedBy) {
                        $k = $tx->currency_code;
                        $summary[$k] ??= ['buy' => '0', 'buy_lek' => '0', 'sell' => '0', 'sell_lek' => '0'];
                        $side = $tx->direction === 'buy' ? 'buy' : 'sell';
                        $summary[$k][$side] = FxMoney::add($summary[$k][$side], (string) $tx->foreign_amount);
                        $summary[$k][$side . '_lek'] = FxMoney::add($summary[$k][$side . '_lek'], (string) $tx->lek_amount);
                    }
                }
            });

        fputcsv($fh, [], ';');
        fputcsv($fh, [$t('summary'), '', '', '', '', '', '', $t('currency'), $t('bought'), 'Lek', $t('sold'), 'Lek'], ';');
        ksort($summary);

        foreach ($summary as $code => $s) {
            fputcsv($fh, ['', '', '', '', '', '', '', $code, $this->num($s['buy']), $this->num($s['buy_lek']), $this->num($s['sell']), $this->num($s['sell_lek'])], ';');
        }

        rewind($fh);

        return (string) stream_get_contents($fh);
    }

    /** Numër me presje dhjetore (formati shqip i Excel-it). */
    private function num(mixed $value, int $decimals = 2): string
    {
        return number_format((float) $value, $decimals, ',', '');
    }
}
