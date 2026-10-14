<?php

namespace Botble\EmironFx\Services;

use Botble\EmironFx\Models\FxAmlCase;
use Botble\EmironFx\Models\FxRate;
use Botble\EmironFx\Models\FxReport;
use Botble\EmironFx\Models\FxTill;
use Botble\EmironFx\Models\FxTillSession;
use Botble\EmironFx\Models\FxTransaction;
use Carbon\CarbonInterface;

/**
 * Paneli i ditës. Të ardhurat nga spread-i janë VLERËSIM kundrejt kursit zyrtar
 * të BSH-së të ruajtur te çdo veprim (blerje: (BSH − kursi) × shuma; shitje:
 * (kursi − BSH) × shuma) — jo fitim kontabël (s'mban parasysh koston e stokut).
 */
class FxDashboardService
{
    public function __construct(
        private readonly FxReportService $reports,
        private readonly FxHoldingService $holding,
        private readonly FxCalendar $calendar,
    ) {
    }

    public function build(CarbonInterface $date): array
    {
        $txs = $this->reports->validTransactions($date);
        // 0.8.0: një kryqëzim (dy këmbisa) numërohet si një veprim; volumet për monedhë i përfshijnë të dyja këmbisat.
        $primary = $txs->reject(fn ($t) => (int) $t->leg === 2);
        $byCurrency = [];
        $spreadTotal = '0';

        foreach ($txs as $tx) {
            $c = $tx->currency_code;
            $byCurrency[$c] ??= ['bought' => '0', 'bought_lek' => '0', 'sold' => '0', 'sold_lek' => '0', 'spread' => '0', 'count' => 0];
            $side = $tx->direction === FxTransaction::BUY ? 'bought' : 'sold';
            $byCurrency[$c][$side] = FxMoney::add($byCurrency[$c][$side], (string) $tx->foreign_amount);
            $byCurrency[$c][$side . '_lek'] = FxMoney::add($byCurrency[$c][$side . '_lek'], (string) $tx->lek_amount);
            $byCurrency[$c]['count']++;

            if ($tx->official_rate !== null) {
                $diff = $tx->direction === FxTransaction::BUY
                    ? FxMoney::sub((string) $tx->official_rate, (string) $tx->rate)
                    : FxMoney::sub((string) $tx->rate, (string) $tx->official_rate);
                $spread = FxMoney::div(FxMoney::mul($diff, (string) $tx->foreign_amount), (string) max(1, (int) $tx->unit));
                $byCurrency[$c]['spread'] = FxMoney::add($byCurrency[$c]['spread'], $spread);
                $spreadTotal = FxMoney::add($spreadTotal, $spread);
            }
        }

        ksort($byCurrency);

        $tills = FxTill::query()->where('is_active', true)->with(['office', 'cashier'])->get()->map(function (FxTill $till) {
            $session = FxTillSession::query()->where('till_id', $till->id)->whereIn('status', ['open', 'closed'])->latest('id')->first();

            return [
                'till' => $till,
                'session' => $session,
                'balances' => array_filter($this->holding->balances($till->id), fn ($v) => ! FxMoney::isZero((string) $v)),
            ];
        });

        $pending = [];
        $day = $this->calendar->previousBusinessDay(now());

        while ($day->lt(now()->startOfDay())) {
            if ($this->reports->validTransactions($day)->isNotEmpty()
                && ! FxReport::query()->where('type', FxReportService::BOA)->whereDate('report_date', $day)->whereNotNull('submitted_at')->exists()) {
                $pending[] = ['date' => $day->copy(), 'deadline' => $this->reports->deadline($day)];
            }

            $day->addDay();
        }

        return [
            'date' => $date,
            'count' => $primary->count(),
            'identified' => $primary->whereNotNull('customer_id')->count(),
            'aif' => $txs->filter(fn ($t) => $t->hasFlag('aif_cash'))->count(),
            'voids' => FxTransaction::query()->whereDate('business_date', $date)->whereNotNull('voids_transaction_id')->count(),
            'currencies' => $byCurrency,
            'spread' => FxMoney::round($spreadTotal, 0),
            'tills' => $tills,
            'holding' => $this->holding->check(),
            'pending' => $pending,
            'amlOpen' => FxAmlCase::query()->where('status', 'open')->count(),
            'lastPublish' => FxRate::query()->latest('id')->value('effective_at'),
            'holiday' => $this->calendar->isHoliday($date),
        ];
    }
}
