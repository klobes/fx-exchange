<?php

namespace Botble\EmironFx\Services;

use Botble\EmironFx\Models\FxPartner;
use Botble\EmironFx\Models\FxPartnerEntry;
use Botble\EmironFx\Models\FxPartnerOperation;
use Botble\EmironFx\Models\FxPartnerService;
use Botble\EmironFx\Models\FxTillMovement;
use Botble\EmironFx\Models\FxTillSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Veprimet me partnerët në sportel (0.7.0) — një vlerë e vetme për veprim
 * (komisioni brenda), e regjistruar në arkën e hapur.
 *
 *   IN  → cash hyn në arkë;   OUT → cash del nga arka.
 *   prefunded: IN zbret gjendjen e parapaguar te partneri, OUT e shton.
 *   account:   OUT → partneri na detyrohet (rimbursim në bankë), IN → ne i detyrohemi.
 *
 * Çdo veprim: numër "P" pa boshllëqe, zinxhir hash, lëvizje arke, rresht në librin e partnerit.
 */
class FxPartnerOpsService
{
    public function __construct(
        private readonly FxLedgerService $ledger,
        private readonly FxHoldingService $holding,
    ) {
    }

    /**
     * Gjendjet e partnerit për monedhë.
     *
     * @return Collection<string, array{prepaid: string, receivable: string}>
     */
    public function balances(int $partnerId, ?\DateTimeInterface $until = null): Collection
    {
        return FxPartnerEntry::query()
            ->where('partner_id', $partnerId)
            ->when($until, fn ($q) => $q->whereDate('entry_date', '<=', $until))
            ->selectRaw('currency_code, SUM(prepaid_delta) as prepaid, SUM(receivable_delta) as receivable')
            ->groupBy('currency_code')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->currency_code => [
                'prepaid' => FxMoney::clean((string) $r->prepaid),
                'receivable' => FxMoney::clean((string) $r->receivable),
            ]]);
    }

    /** @return Collection<int, FxPartnerService> shërbimet aktive, me partnerin */
    public function activeServices(): Collection
    {
        return FxPartnerService::query()
            ->where('is_active', true)
            ->whereHas('partner', fn ($q) => $q->where('is_active', true))
            ->with('partner')
            ->orderBy('partner_id')->orderBy('sort_order')
            ->get();
    }

    public function execute(FxTillSession $session, int $userId, array $data): FxPartnerOperation
    {
        return DB::transaction(function () use ($session, $userId, $data) {
            $session = FxTillSession::query()->whereKey($session->id)->lockForUpdate()->with('till.office')->firstOrFail();

            if (! $session->isOpen() || (int) $session->user_id !== $userId) {
                throw ValidationException::withMessages(['p_amount' => trans('plugins/emiron-fx::fx.counter.errors.session_closed')]);
            }

            $service = FxPartnerService::query()->whereKey((int) ($data['service_id'] ?? 0))->where('is_active', true)->with('partner')->first();

            if (! $service || ! $service->partner?->is_active) {
                throw ValidationException::withMessages(['p_service' => trans('plugins/emiron-fx::fx.partners.errors.service')]);
            }

            $currency = strtoupper((string) ($data['currency_code'] ?? ''));

            if (! in_array($currency, (array) $service->currencies, true)) {
                throw ValidationException::withMessages(['p_currency' => trans('plugins/emiron-fx::fx.partners.errors.currency', ['code' => $currency])]);
            }

            $amount = FxMoney::round(FxMoney::normalize($data['amount'] ?? '0'), 2);

            if (FxMoney::cmp($amount, '0') <= 0) {
                throw ValidationException::withMessages(['p_amount' => trans('plugins/emiron-fx::fx.errors.positive', ['code' => $currency])]);
            }

            $isIn = $service->direction === 'in';

            // Arka: OUT kërkon cash; IN s'duhet ta çojë subjektin mbi kufirin.
            if (! $isIn) {
                $balance = $this->holding->balances($session->till_id)[$currency] ?? '0';

                if (FxMoney::cmp($balance, $amount) < 0) {
                    throw ValidationException::withMessages(['p_amount' => trans('plugins/emiron-fx::fx.counter.errors.insufficient', ['code' => $currency, 'balance' => FxMoney::clean($balance)])]);
                }
            } else {
                $check = $this->holding->check([$currency => $amount]);

                if ($check['exceeds']) {
                    throw ValidationException::withMessages(['p_amount' => trans('plugins/emiron-fx::fx.counter.errors.holding_limit', $check)]);
                }
            }

            // Gjendja e parapaguar duhet të mbulojë pagesën (paneli i partnerit s'e lejon ndryshe).
            if ($service->settlement === 'prefunded' && $isIn) {
                $prepaid = $this->balances($service->partner_id)->get($currency)['prepaid'] ?? '0';

                if (FxMoney::cmp($prepaid, $amount) < 0) {
                    throw ValidationException::withMessages(['p_amount' => trans('plugins/emiron-fx::fx.partners.errors.prepaid', [
                        'partner' => $service->partner->name, 'balance' => FxMoney::clean($prepaid), 'code' => $currency,
                    ])]);
                }
            }

            $op = $this->record($session, $userId, [
                'service' => $service,
                'direction' => $service->direction,
                'settlement' => $service->settlement,
                'currency' => $currency,
                'amount' => $amount,
                'reference' => mb_substr(trim((string) ($data['reference'] ?? '')), 0, 80) ?: null,
            ]);

            return $op;
        });
    }

    public function void(FxPartnerOperation $original, int $userId, string $reason): FxPartnerOperation
    {
        return DB::transaction(function () use ($original, $userId, $reason) {
            $original = FxPartnerOperation::query()->whereKey($original->id)->lockForUpdate()->with('service')->firstOrFail();
            $session = FxTillSession::query()->whereKey($original->till_session_id)->lockForUpdate()->with('till.office')->firstOrFail();

            if ($original->isReversal() || FxPartnerOperation::query()->where('voids_operation_id', $original->id)->exists()) {
                throw ValidationException::withMessages(['reason' => trans('plugins/emiron-fx::fx.counter.errors.already_voided')]);
            }

            if (! $session->isOpen() || ! $original->business_date->isSameDay(now())) {
                throw ValidationException::withMessages(['reason' => trans('plugins/emiron-fx::fx.counter.errors.void_window')]);
            }

            if (mb_strlen(trim($reason)) < 5) {
                throw ValidationException::withMessages(['reason' => trans('plugins/emiron-fx::fx.counter.errors.void_reason')]);
            }

            // Anulimi i një IN e nxjerr cash-in nga arka: duhet të jetë aty.
            if ($original->direction === 'in') {
                $balance = $this->holding->balances($session->till_id)[$original->currency_code] ?? '0';

                if (FxMoney::cmp($balance, (string) $original->amount) < 0) {
                    throw ValidationException::withMessages(['reason' => trans('plugins/emiron-fx::fx.counter.errors.insufficient', ['code' => $original->currency_code, 'balance' => FxMoney::clean($balance)])]);
                }
            }

            return $this->record($session, $userId, [
                'service' => $original->service,
                'direction' => $original->direction,
                'settlement' => $original->settlement,
                'currency' => $original->currency_code,
                'amount' => FxMoney::clean((string) $original->amount),
                'reference' => $original->reference,
                'voids' => $original,
                'reason' => mb_substr(trim($reason), 0, 500),
            ]);
        });
    }

    private function record(FxTillSession $session, int $userId, array $p): FxPartnerOperation
    {
        $office = $session->till->office;
        $now = now()->startOfSecond();
        $year = (int) $now->format('Y');
        $slot = $this->ledger->reserve($office->id, $office->code, $year, 'P');
        $voids = $p['voids'] ?? null;

        $data = [
            'office_id' => $office->id,
            'year' => $year,
            'seq' => $slot['seq'],
            'number' => $slot['number'],
            'till_id' => $session->till_id,
            'till_session_id' => $session->id,
            'user_id' => $userId,
            'business_date' => $now->toDateString(),
            'occurred_at' => $now->format('Y-m-d H:i:s'),
            'partner_id' => $p['service']->partner_id,
            'service_id' => $p['service']->id,
            'direction' => $p['direction'],
            'settlement' => $p['settlement'],
            'currency_code' => $p['currency'],
            'amount' => FxMoney::clean($p['amount'], 2),
            'reference' => $p['reference'],
            'voids_operation_id' => $voids?->id,
            'void_reason' => $p['reason'] ?? null,
            'prev_hash' => $slot['prev_hash'],
        ];
        $data['hash'] = $this->ledger->hashPartner($data, $slot['prev_hash']);

        $op = FxPartnerOperation::query()->create($data);

        // Arka: IN +, OUT −; anulimi e përmbys.
        $sign = ($p['direction'] === 'in' ? 1 : -1) * ($voids ? -1 : 1);
        FxTillMovement::query()->create([
            'till_id' => $session->till_id,
            'till_session_id' => $session->id,
            'currency_code' => $p['currency'],
            'amount' => FxMoney::clean(FxMoney::mul($data['amount'], (string) $sign)),
            'type' => $voids ? 'partner_void' : ($p['direction'] === 'in' ? 'partner_in' : 'partner_out'),
            'note' => $op->number . ($p['reference'] ? ' · ' . $p['reference'] : ''),
            'created_by' => $userId,
        ]);

        // Libri i partnerit.
        $amount = $data['amount'];
        $voidSign = $voids ? '-1' : '1';

        if ($p['settlement'] === 'prefunded') {
            $prepaid = FxMoney::mul($p['direction'] === 'in' ? FxMoney::neg($amount) : $amount, $voidSign);
            $receivable = '0';
        } else {
            $prepaid = '0';
            $receivable = FxMoney::mul($p['direction'] === 'out' ? $amount : FxMoney::neg($amount), $voidSign);
        }

        FxPartnerEntry::query()->create([
            'partner_id' => $data['partner_id'],
            'entry_date' => $data['business_date'],
            'type' => $voids ? 'void' : ($p['settlement'] === 'prefunded' ? 'consumption' : ($p['direction'] === 'out' ? 'receivable' : 'payable')),
            'currency_code' => $p['currency'],
            'prepaid_delta' => FxMoney::clean($prepaid),
            'receivable_delta' => FxMoney::clean($receivable),
            'operation_id' => $op->id,
            'note' => $op->number,
            'created_by' => $userId,
        ]);

        $this->ledger->commit($slot['sequence'], $slot['seq'], $data['hash']);

        return $op;
    }

    /**
     * Gjendja e mbetur e parapaguar për çdo partner aktiv me shërbim të parapaguar (0.8.4, për sportelin).
     * `balance` = gjendja në Lek (ALL), `others` = monedhat e tjera, `low` = nën pragun e njoftimit.
     *
     * @return array<int, array{partner: FxPartner, balance: string, others: array<string,string>, alert: string, low: bool}>
     */
    public function prepaidOverview(): array
    {
        $partnerIds = FxPartnerService::query()->where('is_active', true)->where('settlement', 'prefunded')->pluck('partner_id')->unique()->all();

        if (! $partnerIds) {
            return [];
        }

        $out = [];

        foreach (FxPartner::query()->where('is_active', true)->whereIn('id', $partnerIds)->orderBy('name')->get() as $partner) {
            $balances = $this->balances($partner->id);
            $all = $balances->get('ALL')['prepaid'] ?? '0';
            $alert = (string) ($partner->prepaid_low_alert ?? '0');

            $out[] = [
                'partner' => $partner,
                'balance' => $all,
                'others' => $balances->except('ALL')->map(fn ($b) => $b['prepaid'])->filter(fn ($v) => FxMoney::cmp($v, '0') !== 0)->all(),
                'alert' => $alert,
                'low' => FxMoney::cmp($alert, '0') > 0 && FxMoney::cmp($all, $alert) < 0,
            ];
        }

        return $out;
    }

    /** Partnerët me gjendje të parapaguar nën pragun e njoftimit (Lek). */
    public function lowPrepaid(): Collection
    {
        return FxPartner::query()->where('is_active', true)->where('prepaid_low_alert', '>', 0)->get()
            ->map(fn (FxPartner $p) => ['partner' => $p, 'balance' => $this->balances($p->id)->get('ALL')['prepaid'] ?? '0'])
            ->filter(fn ($r) => FxMoney::cmp($r['balance'], (string) $r['partner']->prepaid_low_alert) < 0)
            ->values();
    }
}
