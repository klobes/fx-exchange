<?php

namespace Botble\EmironFx\Services;

use Botble\EmironFx\Models\FxCurrency;
use Botble\EmironFx\Models\FxCustomer;
use Botble\EmironFx\Models\FxTillMovement;
use Botble\EmironFx\Models\FxTillSession;
use Botble\EmironFx\Models\FxTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Veprimet në sportel — Faza 2a.
 *
 * Kursi NUK jepet nga arkëtari: merret nga publikimi në fuqi (blerja kur zyra
 * blen, shitja kur zyra shet). Pa komision: Lek = valutë × kurs ÷ njësi,
 * rrumbullakuar në lek_decimals.
 *
 * Kontrollet para ruajtjes:
 *  - seanca e hapur dhe e arkëtarit;
 *  - gjendja e arkës mjafton për atë që del (Lek kur blen, valutë kur shet);
 *  - kufiri i gjendjes së subjektit (< holding_limit_lek);
 *  - > id_threshold_lek (100 000): klient i identifikuar i detyrueshëm (dokument i vlefshëm);
 *    klient i pezulluar → refuzim; PEP → vetëm me lejen fx.counter.pep;
 *  - cash me të njëjtin klient brenda 24 orëve ≥ cdd_threshold_lek (1 000 000): flamuri
 *    `aif_cash` (raportim te AIF-i ditën pasardhëse);
 *  - ≥ boa_individual_eur (veprimi, ose totali i ditës me klientin në atë drejtim):
 *    flamuri `boa_individual` (rresht individual te BSH).
 */
class FxCounterService
{
    public function __construct(
        private readonly FxRateService $rates,
        private readonly FxHoldingService $holding,
        private readonly FxLedgerService $ledger,
        private readonly FxCustomerService $customers,
    ) {
    }

    /** Kuotimi për ekranin e sportelit (pa ruajtur). */
    public function quote(string $direction, FxCurrency $currency, string $foreign): ?array
    {
        $rate = $this->rates->currentRates()->get($currency->id);

        if (! $rate) {
            return null;
        }

        $applied = $direction === FxTransaction::BUY ? (string) $rate->buy_rate : (string) $rate->sell_rate;
        $lek = FxMoney::lekFor(FxMoney::normalize($foreign), $applied, (int) $currency->unit, (int) config('plugins.emiron-fx.general.lek_decimals', 0));

        return ['rate' => $rate, 'applied' => $applied, 'lek' => $lek];
    }

    public function execute(FxTillSession $session, int $userId, array $data): FxTransaction
    {
        // 0.8.0: monedhë "në këmbim të" ≠ Lek → kryqëzim valutë↔valutë (dy këmbisa të lidhura, një veprim për klientin).
        if (! empty($data['pay_currency_id'])) {
            return app(FxCrossService::class)->execute($session, $userId, $data);
        }

        return DB::transaction(function () use ($session, $userId, $data) {
            $session = FxTillSession::query()->whereKey($session->id)->lockForUpdate()->with('till.office')->firstOrFail();

            if (! $session->isOpen() || (int) $session->user_id !== $userId) {
                throw ValidationException::withMessages(['foreign_amount' => trans('plugins/emiron-fx::fx.counter.errors.session_closed')]);
            }

            $direction = $data['direction'] === FxTransaction::SELL ? FxTransaction::SELL : FxTransaction::BUY;
            $currency = FxCurrency::query()->active()->whereKey((int) $data['currency_id'])->first();

            if (! $currency) {
                throw ValidationException::withMessages(['currency_id' => trans('plugins/emiron-fx::fx.errors.currency_inactive')]);
            }

            $foreign = FxMoney::normalize($data['foreign_amount']);

            if (FxMoney::cmp($foreign, '0') <= 0) {
                throw ValidationException::withMessages(['foreign_amount' => trans('plugins/emiron-fx::fx.errors.positive', ['code' => $currency->code])]);
            }

            $foreign = FxMoney::round($foreign, min(2, (int) $currency->decimals));
            $quote = $this->quote($direction, $currency, $foreign);

            if (! $quote) {
                throw ValidationException::withMessages(['currency_id' => trans('plugins/emiron-fx::fx.counter.errors.no_rate', ['code' => $currency->code])]);
            }

            $lek = $quote['lek'];
            $categories = (array) config('plugins.emiron-fx.general.client_categories', []);
            $category = in_array($data['client_category'] ?? null, $categories, true) ? $data['client_category'] : ($categories[0] ?? 'Individë');

            // Klienti: i zgjedhur (customer_id) ose i ri nga dokumenti (customer[...]).
            $customer = $this->resolveCustomer($data, $userId);
            $idThreshold = (string) config('plugins.emiron-fx.general.id_threshold_lek', '100000');

            if (! $customer && FxMoney::cmp($lek, $idThreshold) > 0) {
                throw ValidationException::withMessages(['customer' => trans('plugins/emiron-fx::fx.counter.errors.id_required', [
                    'threshold' => number_format((float) $idThreshold, 0, ',', ' '),
                ])]);
            }

            if ($customer) {
                if ($customer->is_blocked) {
                    throw ValidationException::withMessages(['customer' => trans('plugins/emiron-fx::fx.counter.errors.customer_blocked')]);
                }

                if ($customer->docExpired()) {
                    throw ValidationException::withMessages(['customer' => trans('plugins/emiron-fx::fx.counter.errors.doc_expired')]);
                }

                if ($customer->is_pep && ! fx_can('fx.counter.pep')) {
                    throw ValidationException::withMessages(['customer' => trans('plugins/emiron-fx::fx.counter.errors.pep')]);
                }

                $category = $customer->client_category;
            }

            // Gjendja e arkës për atë që del.
            $balances = $this->holding->balances($session->till_id);
            [$outCode, $outAmount] = $direction === FxTransaction::BUY ? ['ALL', $lek] : [$currency->code, $foreign];

            if (FxMoney::cmp($balances[$outCode] ?? '0', $outAmount) < 0) {
                throw ValidationException::withMessages(['foreign_amount' => trans('plugins/emiron-fx::fx.counter.errors.insufficient', [
                    'code' => $outCode, 'balance' => FxMoney::clean($balances[$outCode] ?? '0'),
                ])]);
            }

            // Kufiri i gjendjes së subjektit.
            $delta = $direction === FxTransaction::BUY
                ? [$currency->code => $foreign, 'ALL' => FxMoney::neg($lek)]
                : [$currency->code => FxMoney::neg($foreign), 'ALL' => $lek];
            $holding = $this->holding->check($delta);

            if ($holding['exceeds'] && FxMoney::cmp($holding['after'], $holding['before']) > 0) {
                throw ValidationException::withMessages(['foreign_amount' => trans('plugins/emiron-fx::fx.counter.errors.holding_limit', $holding)]);
            }

            // Sinjalet.
            $flags = [];
            $official = $this->rates->officialRates()->get($currency->id);
            $eurEquivalent = $this->eurEquivalent($lek);

            if ($customer) {
                $flags[] = 'cdd';

                // Veprime të lidhura: cash me këtë klient në 24 orët e fundit + ky veprim.
                $cashLek24h = FxMoney::add($this->customerLekSince($customer->id, now()->subDay()), $lek);

                if (FxMoney::cmp($cashLek24h, (string) config('plugins.emiron-fx.general.cdd_threshold_lek', '1000000')) >= 0) {
                    $flags[] = 'aif_cash';
                }

                // Rresht individual te BSH: totali i ditës me klientin në këtë drejtim.
                $eurToday = $this->customerEurToday($customer->id, $direction);

                if ($eurEquivalent !== null && FxMoney::cmp(FxMoney::add($eurToday, $eurEquivalent), (string) config('plugins.emiron-fx.general.boa_individual_eur', '50000')) >= 0) {
                    $flags[] = 'boa_individual';
                }

                if ($customer->is_pep) {
                    $flags[] = 'pep';
                }
            }

            if ($holding['warn']) {
                $flags[] = 'holding_warn';
            }

            if ($this->rates->isStale($quote['rate'])) {
                $flags[] = 'stale_rate';
            }

            // 0.8.4: mbrojtja nga humbja — shitje (zyra SHET valutë) me kurs nën koston mesatare të stokut.
            $guardEval = null;

            if ($direction === FxTransaction::SELL) {
                $guard = app(LossGuardService::class);
                $guardEval = $guard->evaluate($currency, (string) $quote['applied'], $foreign);

                if ($guardFlag = $guard->enforce(
                    $guardEval,
                    fx_can('fx.counter.below_cost'),
                    ! empty($data['allow_below_cost']),
                    isset($data['below_cost_reason']) ? (string) $data['below_cost_reason'] : null
                )) {
                    $flags[] = $guardFlag;
                }
            }

            $tx = $this->record($session, $userId, [
                'direction' => $direction,
                'currency' => $currency,
                'foreign' => $foreign,
                'rate' => $quote['applied'],
                'rate_id' => $quote['rate']->id,
                'lek' => $lek,
                'official' => $official,
                'eur' => $eurEquivalent,
                'category' => $category,
                'customer_id' => $customer?->id,
                'flags' => $flags,
                'cdd_reference' => null,
            ]);

            if ($guardEval && in_array('below_cost', $flags, true)) {
                app(LossGuardService::class)->record($guardEval, $tx->number, $tx->id, isset($data['below_cost_reason']) ? (string) $data['below_cost_reason'] : null);
            }

            return $tx;
        });
    }

    /**
     * Anulimi: vetëm i njëjti ditë, seanca ende e hapur, nga supervizori, me arsye.
     * Krijon veprim të kundërt (numër i ri në zinxhir) dhe rikthen arkën.
     */
    public function void(FxTransaction $original, int $userId, string $reason): FxTransaction
    {
        return DB::transaction(function () use ($original, $userId, $reason) {
            $original = FxTransaction::query()->whereKey($original->id)->lockForUpdate()->firstOrFail();
            $session = FxTillSession::query()->whereKey($original->till_session_id)->lockForUpdate()->with('till.office')->firstOrFail();

            // 0.8.0: një kryqëzim anulohet i plotë (të dyja këmbisat, një arsye, një transaksion DB).
            $legs = $this->legsOf($original);

            foreach ($legs as $leg) {
                if ($leg->isReversal()) {
                    throw ValidationException::withMessages(['reason' => trans('plugins/emiron-fx::fx.counter.errors.void_reversal')]);
                }

                if (FxTransaction::query()->where('voids_transaction_id', $leg->id)->exists()) {
                    throw ValidationException::withMessages(['reason' => trans('plugins/emiron-fx::fx.counter.errors.already_voided')]);
                }
            }

            if (! $session->isOpen() || ! $original->business_date->isSameDay(now())) {
                throw ValidationException::withMessages(['reason' => trans('plugins/emiron-fx::fx.counter.errors.void_window')]);
            }

            if (trim($reason) === '') {
                throw ValidationException::withMessages(['reason' => trans('plugins/emiron-fx::fx.counter.errors.void_reason')]);
            }

            // Rikthimi s'duhet ta çojë arkën në negativ (p.sh. valuta e blerë u shit ndërkohë): efekti neto për monedhë.
            $delta = [];

            foreach ($legs as $leg) {
                $foreign = (string) $leg->foreign_amount;
                $lek = (string) $leg->lek_amount;
                $isBuy = $leg->direction === FxTransaction::BUY;

                $delta[$leg->currency_code] = FxMoney::add($delta[$leg->currency_code] ?? '0', $isBuy ? FxMoney::neg($foreign) : $foreign);
                $delta['ALL'] = FxMoney::add($delta['ALL'] ?? '0', $isBuy ? $lek : FxMoney::neg($lek));
            }

            $balances = app(FxHoldingService::class)->balances($session->till_id);

            foreach ($delta as $code => $change) {
                if (FxMoney::cmp($change, '0') < 0 && FxMoney::cmp($balances[$code] ?? '0', FxMoney::neg($change)) < 0) {
                    throw ValidationException::withMessages(['reason' => trans('plugins/emiron-fx::fx.counter.errors.insufficient', [
                        'code' => $code, 'balance' => FxMoney::clean($balances[$code] ?? '0'),
                    ])]);
                }
            }

            $first = null;

            foreach ($legs as $leg) {
                $reversal = $this->record($session, $userId, [
                    'direction' => $leg->direction,
                    'currency' => $leg->currency()->first() ?? FxCurrency::query()->findOrFail($leg->currency_id),
                    'foreign' => (string) $leg->foreign_amount,
                    'rate' => (string) $leg->rate,
                    'rate_id' => $leg->rate_id,
                    'lek' => (string) $leg->lek_amount,
                    'official_raw' => [$leg->official_rate, $leg->official_rate_date],
                    'eur' => $leg->eur_equivalent,
                    'category' => $leg->client_category,
                    'customer_id' => $leg->customer_id,
                    'flags' => (array) $leg->flags,
                    'cdd_reference' => $leg->cdd_reference,
                    'voids' => $leg,
                    'reason' => mb_substr(trim($reason), 0, 500),
                ] + ($leg->isCross() ? [
                    // anulimet e një kryqëzimi mbajnë të njëjtën shenjë (leg/pair) që të shfaqen si një veprim
                    'leg' => (int) $leg->leg,
                    'pair_id' => $first?->id,
                ] : []));

                $first ??= $reversal;
            }

            return $first;
        });
    }

    /**
     * Këmbisat e një veprimi (të kyçura): [këmbisa 1, këmbisa 2] për kryqëzim, [vetë veprimi] ndryshe.
     *
     * @return \Illuminate\Support\Collection<int, FxTransaction>
     */
    private function legsOf(FxTransaction $tx): \Illuminate\Support\Collection
    {
        if (! $tx->isCross()) {
            return collect([$tx]);
        }

        $first = (int) $tx->leg === 1
            ? $tx
            : FxTransaction::query()->whereKey($tx->pair_transaction_id)->lockForUpdate()->firstOrFail();

        $second = FxTransaction::query()->where('pair_transaction_id', $first->id)->lockForUpdate()->first();

        return collect([$first, $second])->filter()->values();
    }

    public function resolveCustomer(array $data, int $userId): ?FxCustomer
    {
        if (! empty($data['customer_id'])) {
            return FxCustomer::query()->find((int) $data['customer_id'])
                ?? throw ValidationException::withMessages(['customer' => trans('plugins/emiron-fx::fx.counter.errors.customer_missing')]);
        }

        $new = (array) ($data['customer'] ?? []);

        if (trim((string) ($new['doc_number'] ?? '')) === '') {
            return null;
        }

        return $this->customers->findOrCreate($new + ['_prefix' => 'customer.'], $userId);
    }

    /** Cash në Lek me klientin që nga $since (pa anulimet dhe të anuluarat). */
    public function customerLekSince(int $customerId, \DateTimeInterface $since): string
    {
        // 0.8.0: këmbisa 2 e kryqëzimit s'numërohet (vlera në Lek numërohet një herë, te këmbisa 1).
        return FxMoney::clean((string) FxTransaction::query()
            ->notSecondLeg()
            ->where('customer_id', $customerId)
            ->where('occurred_at', '>=', $since)
            ->whereNull('voids_transaction_id')
            ->whereDoesntHave('voidedBy')
            ->sum('lek_amount'));
    }

    /** Ekuivalenti në EUR i veprimeve të sotme me klientin, në një drejtim. */
    public function customerEurToday(int $customerId, string $direction): string
    {
        return FxMoney::clean((string) FxTransaction::query()
            ->where('customer_id', $customerId)
            ->where('direction', $direction)
            ->whereDate('business_date', now()->toDateString())
            ->whereNull('voids_transaction_id')
            ->whereDoesntHave('voidedBy')
            ->sum('eur_equivalent'));
    }

    /** Ekuivalenti në EUR me kursin zyrtar të BSH-së (për pragun e raportimit). */
    public function eurEquivalent(string $lek): ?string
    {
        $eur = FxCurrency::query()->where('code', 'EUR')->first();
        $official = $eur ? $this->rates->officialRates()->get($eur->id) : null;

        if (! $official) {
            return null;
        }

        $perEur = FxMoney::div((string) $official->rate, (string) max(1, $eur->unit));

        return FxMoney::round(FxMoney::div($lek, $perEur), 2);
    }

    /** Shkruan veprimin + lëvizjet e arkës + zinxhirin. Brenda DB::transaction. */
    public function record(FxTillSession $session, int $userId, array $p): FxTransaction
    {
        $office = $session->till->office;
        $now = now()->startOfSecond();
        $year = (int) $now->format('Y');
        $slot = $this->ledger->reserve($office->id, $office->code, $year);
        $voids = $p['voids'] ?? null;

        [$officialRate, $officialDate] = $p['official_raw'] ?? [$p['official']?->rate, $p['official']?->rate_date];

        // Vlerat në të njëjtin format si i kthen DB-ja (për verifikimin e hash-it).
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
            'direction' => $p['direction'],
            'currency_id' => $p['currency']->id,
            'currency_code' => $p['currency']->code,
            'unit' => (int) $p['currency']->unit,
            'foreign_amount' => FxMoney::clean((string) $p['foreign'], 2),
            'rate' => FxMoney::clean((string) $p['rate'], 6),
            'lek_amount' => FxMoney::clean((string) $p['lek'], 2),
            'rate_id' => $p['rate_id'],
            'official_rate' => $officialRate !== null ? FxMoney::clean((string) $officialRate, 6) : null,
            'official_rate_date' => $officialDate ? (is_string($officialDate) ? $officialDate : $officialDate->format('Y-m-d')) : null,
            'eur_equivalent' => $p['eur'] !== null ? FxMoney::clean((string) $p['eur'], 2) : null,
            'client_category' => $p['category'],
            'customer_id' => $p['customer_id'] ?? null,
            'payment_method' => 'cash',
            'flags' => array_values(array_unique($p['flags'])),
            'cdd_reference' => $p['cdd_reference'],
            'voids_transaction_id' => $voids?->id,
            'void_reason' => $p['reason'] ?? null,
            'prev_hash' => $slot['prev_hash'],
        ];

        // 0.8.0: këmbisat e kryqëzimit (çelësat shtohen vetëm kur ka leg; kolonat ekzistojnë pas migrimit 2026_10_09_000008).
        if (isset($p['leg'])) {
            $data['leg'] = (int) $p['leg'];
            $data['pair_transaction_id'] = $p['pair_id'] ?? null;

            if (isset($p['change_amount']) && FxMoney::cmp((string) $p['change_amount'], '0') > 0) {
                $data['change_amount'] = FxMoney::clean((string) $p['change_amount'], 2);
                $data['change_currency'] = (string) $p['change_currency'];
            }
        }

        $data['hash'] = $this->ledger->hash($data, $slot['prev_hash']);

        $tx = FxTransaction::query()->create($data);

        // Lëvizjet e arkës: blerje → valuta hyn, Leku del; shitje → e kundërta. Anulimi i përmbys.
        $sign = $voids ? '-1' : '1';
        $foreignMove = FxMoney::mul($p['direction'] === FxTransaction::BUY ? $data['foreign_amount'] : FxMoney::neg($data['foreign_amount']), $sign);
        $lekMove = FxMoney::mul($p['direction'] === FxTransaction::BUY ? FxMoney::neg($data['lek_amount']) : $data['lek_amount'], $sign);

        foreach ([[$data['currency_code'], $foreignMove], ['ALL', $lekMove]] as [$code, $amount]) {
            FxTillMovement::query()->create([
                'till_id' => $session->till_id,
                'till_session_id' => $session->id,
                'currency_code' => $code,
                'amount' => FxMoney::clean($amount),
                'type' => $voids ? 'void' : 'transaction',
                'transaction_id' => $tx->id,
                'created_by' => $userId,
            ]);
        }

        $this->ledger->commit($slot['sequence'], $slot['seq'], $data['hash']);

        return $tx;
    }
}
