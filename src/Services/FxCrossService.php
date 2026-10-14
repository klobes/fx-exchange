<?php

namespace Botble\EmironFx\Services;

use Botble\EmironFx\Models\FxCurrency;
use Botble\EmironFx\Models\FxTillSession;
use Botble\EmironFx\Models\FxTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kryqëzimi valutë↔valutë (0.8.0): klienti jep monedhën A dhe merr monedhën B.
 *
 * Në regjistër = DY këmbisa të lidhura me të njëjtën vlerë në Lek (siç e bënte arkëtari dorazi: blen A kundrejt Lek,
 * shet B kundrejt të njëjtave Lek), por për klientin dhe sportelin është NJË veprim, një kupon (numri i këmbisës 1):
 *   këmbisa 1 (leg 1) — zyra BLEN A:  A hyn në arkë, Leku del;      kursi = buy_rate(A)
 *   këmbisa 2 (leg 2) — zyra SHET B:  B del nga arka, Leku hyn;      kursi = sell_rate(B)
 *
 * Llogaritja (pa rrumbullakim në shuma të plota; maksimumi 2 shifra pas pikës):
 *   "klienti jep a" (zyra blen A):    L1 = a × buy_A ÷ unit_A;  b = poshtë(L1 ÷ (sell_B ÷ unit_B), 2);  L2 = b × sell_B ÷ unit_B
 *       kusuri = L1 − L2 → në Lek (parazgjedhje; Leku neto i arkës = −kusuri), ose në A (change_in=given):
 *       a_përdorur = lart(L2 ÷ (buy_A ÷ unit_A), 2), kusuri_A = a − a_përdorur, dhe të dyja këmbisat marrin Lek = L2.
 *   "klienti dëshiron b" (zyra shet B): L2 = b × sell_B ÷ unit_B;  a = lart(L2 ÷ (buy_A ÷ unit_A), 2);  të dyja këmbisat Lek = L2; pa kusur.
 * Mbetja nën 1 Lek (kur Leku është me shuma të plota) mbetet te zyra.
 *
 * Kontrollet: njëlloj si te këmbimi me Lek (seanca, monedhat, kursi, identifikimi mbi pragun e Lekut, PEP/pezullim,
 * gjendja e arkës për atë që del, kufiri i gjendjes). AIF: vlera në Lek numërohet VETËM një herë (këmbisa 2 s'numërohet).
 * Çelës: FX_CROSS_ENABLED (parazgjedhje: i fikur).
 */
class FxCrossService
{
    public function __construct(
        private readonly FxRateService $rates,
        private readonly FxHoldingService $holding,
        private readonly FxCounterService $counter,
    ) {
    }

    /**
     * Kuotimi i çiftit (pa ruajtur).
     *
     * @param  string  $direction  buy = zyra blen $main nga klienti (klienti jep $main, merr $other);
     *                             sell = zyra shet $main klientit (klienti jep $other, merr $main)
     * @param  string  $changeIn   lek | given (kusuri në monedhën e dhënë; vetëm kur klienti jep një shumë të caktuar)
     * @return array{
     *   isBuy: bool, A: FxCurrency, B: FxCurrency, rateA: object, rateB: object, buy: string, sell: string,
     *   given: string, a: string, b: string, lek1: string, lek2: string, change_lek: string, change_a: string
     * }
     *
     * @throws ValidationException
     */
    public function quote(FxCurrency $main, FxCurrency $other, string $direction, string $amount, string $changeIn = 'lek'): array
    {
        $isBuy = $direction !== FxTransaction::SELL;
        $A = $isBuy ? $main : $other; // klienti jep (zyra blen)
        $B = $isBuy ? $other : $main; // klienti merr (zyra shet)

        $current = $this->rates->currentRates();
        $rateA = $current->get($A->id);
        $rateB = $current->get($B->id);

        foreach ([[$A, $rateA], [$B, $rateB]] as [$currency, $rate]) {
            if (! $rate) {
                throw ValidationException::withMessages(['currency_id' => trans('plugins/emiron-fx::fx.counter.errors.no_rate', ['code' => $currency->code])]);
            }
        }

        $lekDec = (int) config('plugins.emiron-fx.general.lek_decimals', 0);
        $dA = min(2, (int) $A->decimals);
        $dB = min(2, (int) $B->decimals);
        $unitA = (string) max(1, (int) $A->unit);
        $unitB = (string) max(1, (int) $B->unit);
        $buy = (string) $rateA->buy_rate;
        $sell = (string) $rateB->sell_rate;

        $amount = FxMoney::normalize($amount);

        if (FxMoney::cmp($amount, '0') <= 0) {
            throw ValidationException::withMessages(['foreign_amount' => trans('plugins/emiron-fx::fx.errors.positive', ['code' => $main->code])]);
        }

        $changeLek = '0';
        $changeA = '0';

        if ($isBuy) {
            $given = FxMoney::round($amount, $dA);
            $a = $given;
            $lek1 = FxMoney::lekFor($a, $buy, (int) $unitA, $lekDec);
            $b = FxMoney::floor(FxMoney::div(FxMoney::mul($lek1, $unitB), $sell), $dB);
            $lek2 = FxMoney::lekFor($b, $sell, (int) $unitB, $lekDec);

            if (FxMoney::cmp($lek2, $lek1) > 0) {
                $lek2 = $lek1;
            }

            if ($changeIn === 'given') {
                $a = FxMoney::ceil(FxMoney::div(FxMoney::mul($lek2, $unitA), $buy), $dA);

                if (FxMoney::cmp($a, $given) > 0) {
                    $a = $given;
                }

                $changeA = FxMoney::sub($given, $a);
                $lek1 = $lek2; // Leku fizik nuk lëviz: të dyja këmbisat mbajnë të njëjtën vlerë
            } else {
                $changeLek = FxMoney::sub($lek1, $lek2);
            }
        } else {
            $b = FxMoney::round($amount, $dB);
            $lek2 = FxMoney::lekFor($b, $sell, (int) $unitB, $lekDec);
            $a = FxMoney::ceil(FxMoney::div(FxMoney::mul($lek2, $unitA), $buy), $dA);
            $given = $a;
            $lek1 = $lek2;
        }

        if (FxMoney::cmp($a, '0') <= 0 || FxMoney::cmp($b, '0') <= 0 || FxMoney::cmp($lek2, '0') <= 0) {
            throw ValidationException::withMessages(['foreign_amount' => trans('plugins/emiron-fx::fx.counter.errors.cross_small')]);
        }

        return [
            'isBuy' => $isBuy, 'A' => $A, 'B' => $B, 'rateA' => $rateA, 'rateB' => $rateB, 'buy' => $buy, 'sell' => $sell,
            'given' => $given, 'a' => $a, 'b' => $b, 'lek1' => $lek1, 'lek2' => $lek2,
            'change_lek' => FxMoney::clean($changeLek, 2), 'change_a' => FxMoney::clean($changeA, 2),
        ];
    }

    /** Kryen kryqëzimin; kthen këmbisën 1 (kuponi është i saj; këmbisa 2 gjendet me `pairLeg`). */
    public function execute(FxTillSession $session, int $userId, array $data): FxTransaction
    {
        return DB::transaction(function () use ($session, $userId, $data) {
            $session = FxTillSession::query()->whereKey($session->id)->lockForUpdate()->with('till.office')->firstOrFail();

            if (! $session->isOpen() || (int) $session->user_id !== $userId) {
                throw ValidationException::withMessages(['foreign_amount' => trans('plugins/emiron-fx::fx.counter.errors.session_closed')]);
            }

            if (! config('plugins.emiron-fx.general.cross_enabled', false)) {
                throw ValidationException::withMessages(['pay_currency_id' => trans('plugins/emiron-fx::fx.counter.errors.cross_disabled')]);
            }

            $direction = ($data['direction'] ?? '') === FxTransaction::SELL ? FxTransaction::SELL : FxTransaction::BUY;
            $main = FxCurrency::query()->active()->whereKey((int) ($data['currency_id'] ?? 0))->first();
            $other = FxCurrency::query()->active()->whereKey((int) ($data['pay_currency_id'] ?? 0))->first();

            if (! $main) {
                throw ValidationException::withMessages(['currency_id' => trans('plugins/emiron-fx::fx.errors.currency_inactive')]);
            }

            if (! $other) {
                throw ValidationException::withMessages(['pay_currency_id' => trans('plugins/emiron-fx::fx.errors.currency_inactive')]);
            }

            if ($main->id === $other->id) {
                throw ValidationException::withMessages(['pay_currency_id' => trans('plugins/emiron-fx::fx.counter.errors.cross_same')]);
            }

            // Kusuri në monedhën e dhënë ka kuptim vetëm kur klienti jep një shumë të caktuar (zyra blen $main).
            $changeIn = ($direction === FxTransaction::BUY && ($data['change_in'] ?? 'lek') === 'given') ? 'given' : 'lek';
            $q = $this->quote($main, $other, $direction, (string) ($data['foreign_amount'] ?? '0'), $changeIn);

            /** @var FxCurrency $A */
            $A = $q['A'];
            /** @var FxCurrency $B */
            $B = $q['B'];

            $categories = (array) config('plugins.emiron-fx.general.client_categories', []);
            $category = in_array($data['client_category'] ?? null, $categories, true) ? $data['client_category'] : ($categories[0] ?? 'Individë');

            // Klienti: i njëjti rregull si te këmbimi me Lek (identifikimi mbi pragun e vlerës në Lek).
            $customer = $this->counter->resolveCustomer($data, $userId);
            $idThreshold = (string) config('plugins.emiron-fx.general.id_threshold_lek', '100000');

            if (! $customer && FxMoney::cmp($q['lek1'], $idThreshold) > 0) {
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

            // Gjendja e arkës për atë që del: B (shitet) dhe, nëse ka, kusuri në Lek.
            $balances = $this->holding->balances($session->till_id);

            if (FxMoney::cmp($balances[$B->code] ?? '0', $q['b']) < 0) {
                throw ValidationException::withMessages(['foreign_amount' => trans('plugins/emiron-fx::fx.counter.errors.insufficient', [
                    'code' => $B->code, 'balance' => FxMoney::clean($balances[$B->code] ?? '0'),
                ])]);
            }

            if (FxMoney::cmp($q['change_lek'], '0') > 0 && FxMoney::cmp($balances['ALL'] ?? '0', $q['change_lek']) < 0) {
                throw ValidationException::withMessages(['foreign_amount' => trans('plugins/emiron-fx::fx.counter.errors.insufficient', [
                    'code' => 'ALL', 'balance' => FxMoney::clean($balances['ALL'] ?? '0'),
                ])]);
            }

            // Kufiri i gjendjes së subjektit: A hyn, B del, kusuri në Lek del.
            $delta = [$A->code => $q['a'], $B->code => FxMoney::neg($q['b'])];

            if (FxMoney::cmp($q['change_lek'], '0') > 0) {
                $delta['ALL'] = FxMoney::neg($q['change_lek']);
            }

            $holding = $this->holding->check($delta);

            if ($holding['exceeds'] && FxMoney::cmp($holding['after'], $holding['before']) > 0) {
                throw ValidationException::withMessages(['foreign_amount' => trans('plugins/emiron-fx::fx.counter.errors.holding_limit', $holding)]);
            }

            // Sinjalet. AIF: vlera në Lek numërohet një herë (te këmbisa 1).
            $eur1 = $this->counter->eurEquivalent($q['lek1']);
            $eur2 = $this->counter->eurEquivalent($q['lek2']);
            $flags1 = ['cross'];
            $flags2 = ['cross'];

            if ($customer) {
                $flags1[] = 'cdd';
                $flags2[] = 'cdd';

                $cashLek24h = FxMoney::add($this->counter->customerLekSince($customer->id, now()->subDay()), $q['lek1']);

                if (FxMoney::cmp($cashLek24h, (string) config('plugins.emiron-fx.general.cdd_threshold_lek', '1000000')) >= 0) {
                    $flags1[] = 'aif_cash';
                }

                $boaThreshold = (string) config('plugins.emiron-fx.general.boa_individual_eur', '50000');

                if ($eur1 !== null && FxMoney::cmp(FxMoney::add($this->counter->customerEurToday($customer->id, FxTransaction::BUY), $eur1), $boaThreshold) >= 0) {
                    $flags1[] = 'boa_individual';
                }

                if ($eur2 !== null && FxMoney::cmp(FxMoney::add($this->counter->customerEurToday($customer->id, FxTransaction::SELL), $eur2), $boaThreshold) >= 0) {
                    $flags2[] = 'boa_individual';
                }

                if ($customer->is_pep) {
                    $flags1[] = 'pep';
                    $flags2[] = 'pep';
                }
            }

            if ($holding['warn']) {
                $flags1[] = 'holding_warn';
            }

            if ($this->rates->isStale($q['rateA'])) {
                $flags1[] = 'stale_rate';
            }

            if ($this->rates->isStale($q['rateB'])) {
                $flags2[] = 'stale_rate';
            }

            $official = $this->rates->officialRates();

            // 0.8.4: mbrojtja nga humbja — këmbisa 2 SHET monedhën B; kurs shitjeje nën koston e stokut të B.
            $guard = app(LossGuardService::class);
            $guardEval = $guard->evaluate($B, $q['sell'], $q['b']);

            if ($guardFlag = $guard->enforce(
                $guardEval,
                fx_can('fx.counter.below_cost'),
                ! empty($data['allow_below_cost']),
                isset($data['below_cost_reason']) ? (string) $data['below_cost_reason'] : null
            )) {
                $flags2[] = $guardFlag;
            }

            $leg1 = $this->counter->record($session, $userId, [
                'direction' => FxTransaction::BUY,
                'currency' => $A,
                'foreign' => $q['a'],
                'rate' => $q['buy'],
                'rate_id' => $q['rateA']->id,
                'lek' => $q['lek1'],
                'official' => $official->get($A->id),
                'eur' => $eur1,
                'category' => $category,
                'customer_id' => $customer?->id,
                'flags' => $flags1,
                'cdd_reference' => null,
                'leg' => 1,
            ]);

            $changeAmount = FxMoney::cmp($q['change_lek'], '0') > 0 ? $q['change_lek'] : $q['change_a'];
            $changeCurrency = FxMoney::cmp($q['change_lek'], '0') > 0 ? 'ALL' : $A->code;

            $leg2 = $this->counter->record($session, $userId, [
                'direction' => FxTransaction::SELL,
                'currency' => $B,
                'foreign' => $q['b'],
                'rate' => $q['sell'],
                'rate_id' => $q['rateB']->id,
                'lek' => $q['lek2'],
                'official' => $official->get($B->id),
                'eur' => $eur2,
                'category' => $category,
                'customer_id' => $customer?->id,
                'flags' => $flags2,
                'cdd_reference' => null,
                'leg' => 2,
                'pair_id' => $leg1->id,
                'change_amount' => $changeAmount,
                'change_currency' => $changeCurrency,
            ]);

            if (in_array('below_cost', $flags2, true)) {
                $guard->record($guardEval, $leg2->number, $leg2->id, isset($data['below_cost_reason']) ? (string) $data['below_cost_reason'] : null);
            }

            return $leg1;
        });
    }
}
