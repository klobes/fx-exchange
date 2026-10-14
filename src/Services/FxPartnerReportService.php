<?php

namespace Botble\EmironFx\Services;

use Botble\EmironFx\Models\FxPartner;
use Botble\EmironFx\Models\FxPartnerOperation;
use Carbon\CarbonInterface;

/**
 * Raporti ditor i partnerëve (hyrje/dalje): për çdo partner → shërbim → monedhë,
 * numri dhe shuma IN/OUT (pa çiftet e anuluara), gjendja e parapaguar dhe detyrimi
 * në fillim dhe në fund të ditës.
 */
class FxPartnerReportService
{
    public function __construct(private readonly FxPartnerOpsService $ops)
    {
    }

    public function build(CarbonInterface $date): array
    {
        $voidedIds = FxPartnerOperation::query()->whereNotNull('voids_operation_id')->pluck('voids_operation_id')->all();

        $ops = FxPartnerOperation::query()
            ->whereDate('business_date', $date->toDateString())
            ->whereNull('voids_operation_id')
            ->whereNotIn('id', $voidedIds ?: [0])
            ->with(['service', 'partner', 'cashier'])
            ->orderBy('id')
            ->get();

        $out = [];

        foreach (FxPartner::query()->orderBy('name')->get() as $partner) {
            $pOps = $ops->where('partner_id', $partner->id);
            $services = [];

            foreach ($pOps->groupBy('service_id') as $serviceOps) {
                $service = $serviceOps->first()->service;

                foreach ($serviceOps->groupBy('currency_code') as $code => $rows) {
                    $services[] = [
                        'service' => $service?->name,
                        'direction' => $service?->direction,
                        'currency' => $code,
                        'count' => $rows->count(),
                        'total' => FxMoney::round($rows->reduce(fn ($c, $o) => FxMoney::add($c, (string) $o->amount), '0'), 2),
                    ];
                }
            }

            $start = $this->ops->balances($partner->id, $date->copy()->subDay());
            $end = $this->ops->balances($partner->id, $date);

            if (! $services && $start->isEmpty() && $end->isEmpty()) {
                continue;
            }

            $out[] = [
                'partner' => $partner,
                'services' => $services,
                'operations' => $pOps->values(),
                'start' => $start,
                'end' => $end,
            ];
        }

        return $out;
    }

    public function csv(CarbonInterface $date): string
    {
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        $t = fn ($k) => trans('plugins/emiron-fx::fx.partner_report.' . $k);

        foreach ($this->build($date) as $p) {
            fputcsv($fh, [$p['partner']->name, $date->format('d.m.Y')], ';');
            fputcsv($fh, [$t('service'), $t('direction'), $t('currency'), $t('count'), $t('total')], ';');

            foreach ($p['services'] as $s) {
                fputcsv($fh, [$s['service'], strtoupper((string) $s['direction']), $s['currency'], $s['count'], str_replace('.', ',', $s['total'])], ';');
            }

            fputcsv($fh, [], ';');
            fputcsv($fh, [$t('currency'), $t('prepaid_start'), $t('prepaid_end'), $t('receivable_start'), $t('receivable_end')], ';');

            foreach ($p['end']->keys()->merge($p['start']->keys())->unique() as $code) {
                fputcsv($fh, [
                    $code,
                    str_replace('.', ',', $p['start'][$code]['prepaid'] ?? '0.00'), str_replace('.', ',', $p['end'][$code]['prepaid'] ?? '0.00'),
                    str_replace('.', ',', $p['start'][$code]['receivable'] ?? '0.00'), str_replace('.', ',', $p['end'][$code]['receivable'] ?? '0.00'),
                ], ';');
            }

            fputcsv($fh, [], ';');
            fputcsv($fh, [$t('number'), $t('time'), $t('service'), $t('currency'), $t('amount'), $t('reference'), $t('cashier')], ';');

            foreach ($p['operations'] as $o) {
                fputcsv($fh, [$o->number, $o->occurred_at->format('H:i:s'), $o->service?->name, $o->currency_code, str_replace('.', ',', (string) $o->amount), $o->reference, $o->cashier?->name], ';');
            }

            fputcsv($fh, [], ';');
        }

        rewind($fh);

        return (string) stream_get_contents($fh);
    }
}
