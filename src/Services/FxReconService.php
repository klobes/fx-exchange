<?php

namespace Botble\EmironFx\Services;

use Botble\EmironFx\Models\FxBankEntry;
use Botble\EmironFx\Models\FxPartner;
use Botble\EmironFx\Models\FxPartnerEntry;
use Botble\EmironFx\Models\FxTillMovement;
use Illuminate\Support\Collection;

/**
 * Qarku i mbyllur: çdo element i hapur në arkë ↔ bankë ↔ partner.
 *   1. Derdhje arke (cash_out) pa lëvizje bankare  → cash në tranzit.
 *   2. Tërheqje (cash_in) pa lëvizje bankare        → cash pa burim të dokumentuar.
 *   3. Lëvizje bankare pa konfirmim kundrejt ekstraktit.
 *   4. Detyrimi i partnerit (p.sh. tërheqjet MG) i parimbursuar, me vjetërsinë (FIFO).
 *   5. Gjendja e parapaguar te partneri (nën pragun e njoftimit).
 */
class FxReconService
{
    public function __construct(
        private readonly FxPartnerOpsService $partners,
        private readonly FxCalendar $calendar,
    ) {
    }

    public function build(): array
    {
        $linked = FxBankEntry::query()->whereNotNull('till_movement_id')
            ->whereDoesntHave('reversal')->pluck('till_movement_id')->all();

        $unmatched = fn (string $type) => FxTillMovement::query()
            ->where('type', $type)
            ->whereNotIn('id', $linked ?: [0])
            ->with('author')
            ->orderBy('created_at')
            ->get();

        $partners = FxPartner::query()->where('is_active', true)->get()->map(function (FxPartner $partner) {
            return [
                'partner' => $partner,
                'balances' => $this->partners->balances($partner->id),
                'aging' => $this->receivableAging($partner->id),
            ];
        });

        return [
            'cashOut' => $unmatched('cash_out'),
            'cashIn' => $unmatched('cash_in'),
            'pendingBank' => FxBankEntry::query()->where('status', 'pending')->with(['account', 'partner'])->orderBy('entry_date')->get(),
            'partners' => $partners,
            'lowPrepaid' => $this->partners->lowPrepaid(),
        ];
    }

    /**
     * Vjetërsia e detyrimit të partnerit (receivable > 0) me FIFO: rimbursimet mbyllin
     * detyrimet më të vjetra. Kthen elementet e pambyllura, me afatin (dita e ardhshme e punës).
     *
     * @return Collection<int, array{date: \Carbon\CarbonInterface, currency: string, open: string, overdue: bool}>
     */
    public function receivableAging(int $partnerId): Collection
    {
        $open = [];

        foreach (FxPartnerEntry::query()->where('partner_id', $partnerId)->orderBy('entry_date')->orderBy('id')->get() as $e) {
            $delta = (string) $e->receivable_delta;
            $code = $e->currency_code;
            $open[$code] ??= [];

            if (FxMoney::cmp($delta, '0') > 0) {
                $open[$code][] = ['date' => $e->entry_date, 'open' => $delta];
                continue;
            }

            $toClose = FxMoney::neg($delta);

            while (FxMoney::cmp($toClose, '0') > 0 && $open[$code]) {
                $first = &$open[$code][0];

                if (FxMoney::cmp($first['open'], $toClose) <= 0) {
                    $toClose = FxMoney::sub($toClose, $first['open']);
                    array_shift($open[$code]);
                } else {
                    $first['open'] = FxMoney::sub($first['open'], $toClose);
                    $toClose = '0';
                }

                unset($first);
            }
        }

        $out = collect();

        foreach ($open as $code => $items) {
            foreach ($items as $item) {
                $due = $this->calendar->nextBusinessDay($item['date'])->endOfDay();
                $out->push(['date' => $item['date'], 'currency' => $code, 'open' => FxMoney::clean($item['open']), 'overdue' => now()->gt($due)]);
            }
        }

        return $out;
    }
}
