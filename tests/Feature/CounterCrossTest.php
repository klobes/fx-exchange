<?php

namespace Botble\EmironFx\Tests\Feature;

use Botble\EmironFx\Models\FxCurrency;
use Botble\EmironFx\Models\FxTill;
use Botble\EmironFx\Models\FxTillSession;
use Botble\EmironFx\Models\FxTransaction;
use Botble\EmironFx\Services\FxCounterService;
use Botble\EmironFx\Services\FxCrossService;
use Botble\EmironFx\Services\FxLedgerService;
use Botble\EmironFx\Services\FxTillService;
use Botble\EmironFx\Tests\Support\FxTestCase;
use Illuminate\Validation\ValidationException;

/**
 * Kryqëzimi valutë↔valutë (0.8.0): dy këmbisa të lidhura, një veprim për klientin.
 *
 * Kurse të testit:  EUR blerje 100 / shitje 102 (BSH 101);  USD blerje 90 / shitje 92 (BSH 91).
 *  Klienti jep 100 EUR → L1 = 10 000 Lek → b = poshtë(10 000 ÷ 92, 2) = 108.69 USD → L2 = 9 999 Lek → kusur 1 Lek.
 */
class CounterCrossTest extends FxTestCase
{
    private FxCurrency $eur;

    private FxCurrency $usd;

    protected function setUp(): void
    {
        parent::setUp();
        config(['plugins.emiron-fx.general.cross_enabled' => true]);
    }

    /** @return array{0: FxTill, 1: FxTillSession, 2: int} [arka, seanca, zyra] */
    private function crossTill(string $lek = '1000000', string $eur = '0', string $usd = '1000'): array
    {
        $office = $this->makeOffice();
        $till = $this->makeTill($office);
        $this->eur = $this->currency('EUR');
        $this->usd = $this->currency('USD');
        $this->setOfficial($this->eur, '101');
        $this->setRate($this->eur, '100', '102');
        $this->setOfficial($this->usd, '91');
        $this->setRate($this->usd, '90', '92');
        $session = $this->openTill($till, ['ALL' => $lek, 'EUR' => $eur, 'USD' => $usd]);

        return [$till, $session, $office];
    }

    /** Zyra blen $amount EUR dhe shet USD (klienti jep EUR). */
    private function buyEurForUsd($session, string $amount, array $extra = [], int $userId = 9001): FxTransaction
    {
        return app(FxCounterService::class)->execute($session, $userId, [
            'direction' => FxTransaction::BUY, 'currency_id' => $this->eur->id, 'pay_currency_id' => $this->usd->id,
            'foreign_amount' => $amount,
        ] + $extra);
    }

    private function refused(callable $fn, string $key): void
    {
        try {
            $fn();
            $this->fail("Pritej ValidationException me çelës '{$key}'");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
        }
    }

    public function test_client_gives_eur_gets_usd_with_lek_change(): void
    {
        [$till, $session] = $this->crossTill();

        $leg1 = $this->buyEurForUsd($session, '100');
        $leg2 = FxTransaction::query()->where('pair_transaction_id', $leg1->id)->firstOrFail();

        // këmbisa 1: zyra BLEN 100 EUR për 10 000 Lek
        $this->assertSame(1, (int) $leg1->leg);
        $this->assertSame('buy', $leg1->direction);
        $this->assertSame('EUR', $leg1->currency_code);
        $this->assertSame('100.00', (string) $leg1->foreign_amount);
        $this->assertSame('10000.00', (string) $leg1->lek_amount);
        $this->assertNull($leg1->pair_transaction_id);

        // këmbisa 2: zyra SHET 108.69 USD për 9 999 Lek; kusuri 1 Lek
        $this->assertSame(2, (int) $leg2->leg);
        $this->assertSame('sell', $leg2->direction);
        $this->assertSame('USD', $leg2->currency_code);
        $this->assertSame('108.69', (string) $leg2->foreign_amount);
        $this->assertSame('9999.00', (string) $leg2->lek_amount);
        $this->assertSame('1.00', (string) $leg2->change_amount);
        $this->assertSame('ALL', $leg2->change_currency);
        $this->assertSame($leg1->id, (int) $leg2->pair_transaction_id);
        $this->assertSame([1, 2], [(int) $leg1->seq, (int) $leg2->seq]);

        // arka: EUR hyn, USD del, Leku neto = −kusuri
        $b = $this->balances($till);
        $this->assertSame('100.00', $b['EUR']);
        $this->assertSame('891.31', $b['USD']);
        $this->assertSame('999999.00', $b['ALL']);

        $this->assertContains('cross', (array) $leg1->flags);
        $this->assertContains('cross', (array) $leg2->flags);
    }

    public function test_change_can_be_returned_in_the_currency_the_customer_gave(): void
    {
        [$till, $session] = $this->crossTill();

        $leg1 = $this->buyEurForUsd($session, '100', ['change_in' => 'given']);
        $leg2 = $leg1->pairLeg;

        // 108.69 USD = 9 999 Lek → klienti jep vetëm 99.99 EUR, merr 0.01 EUR kusur; Leku fizik nuk lëviz
        $this->assertSame('99.99', (string) $leg1->foreign_amount);
        $this->assertSame('9999.00', (string) $leg1->lek_amount);
        $this->assertSame('108.69', (string) $leg2->foreign_amount);
        $this->assertSame('9999.00', (string) $leg2->lek_amount);
        $this->assertSame('0.01', (string) $leg2->change_amount);
        $this->assertSame('EUR', $leg2->change_currency);

        $b = $this->balances($till);
        $this->assertSame('1000000.00', $b['ALL']);
        $this->assertSame('99.99', $b['EUR']);
        $this->assertSame('891.31', $b['USD']);
    }

    public function test_exact_conversion_has_no_change(): void
    {
        [$till, $session] = $this->crossTill();

        // 92 EUR... 92 × 100 = 9 200 Lek → 100 USD (9 200 ÷ 92) saktësisht
        $leg1 = $this->buyEurForUsd($session, '92');
        $leg2 = $leg1->pairLeg;

        $this->assertSame('100.00', (string) $leg2->foreign_amount);
        $this->assertSame('9200.00', (string) $leg2->lek_amount);
        $this->assertNull($leg2->change_amount);
        $this->assertSame('1000000.00', $this->balances($till)['ALL']);
    }

    public function test_client_wants_a_fixed_amount_and_the_given_side_is_rounded_up(): void
    {
        [$till, $session] = $this->crossTill();

        // Zyra shet 100 USD (klienti merr), klienti paguan EUR: L2 = 9 200 → a = lart(9 200 ÷ 100, 2) = 92.00
        $leg1 = app(FxCounterService::class)->execute($session, 9001, [
            'direction' => FxTransaction::SELL, 'currency_id' => $this->usd->id, 'pay_currency_id' => $this->eur->id,
            'foreign_amount' => '100',
        ]);
        $leg2 = $leg1->pairLeg;

        $this->assertSame('EUR', $leg1->currency_code);   // këmbisa 1 = ajo që jep klienti
        $this->assertSame('92.00', (string) $leg1->foreign_amount);
        $this->assertSame('USD', $leg2->currency_code);
        $this->assertSame('100.00', (string) $leg2->foreign_amount);
        $this->assertSame('9200.00', (string) $leg1->lek_amount);
        $this->assertSame('9200.00', (string) $leg2->lek_amount);
        $this->assertNull($leg2->change_amount);

        $b = $this->balances($till);
        $this->assertSame('92.00', $b['EUR']);
        $this->assertSame('900.00', $b['USD']);
        $this->assertSame('1000000.00', $b['ALL']);
    }

    public function test_fixed_amount_rounds_the_given_side_up_to_the_next_cent(): void
    {
        [, $session] = $this->crossTill();
        $this->setRate($this->eur, '97', '99'); // kurs i ri i blerjes së EUR (rreshti i fundit në fuqi)

        // 100 USD × 92 = 9 200 Lek; 9 200 ÷ 97 = 94.8453… EUR → lart në qindarkë = 94.85
        $leg1 = app(FxCounterService::class)->execute($session, 9001, [
            'direction' => FxTransaction::SELL, 'currency_id' => $this->usd->id, 'pay_currency_id' => $this->eur->id,
            'foreign_amount' => '100',
        ]);

        $this->assertSame('94.85', (string) $leg1->foreign_amount);
        $this->assertSame('9200.00', (string) $leg1->lek_amount);
        $this->assertSame('97.000000', (string) $leg1->rate);
        $this->assertSame('100.00', (string) $leg1->pairLeg->foreign_amount);
    }

    public function test_legs_chain_into_the_ledger_and_verify_clean(): void
    {
        [, $session, $office] = $this->crossTill();

        $leg1 = $this->buyEurForUsd($session, '100');
        $leg2 = $leg1->pairLeg;
        $this->buyEurForUsd($session, '50', ['change_in' => 'given']);

        $this->assertSame($leg1->hash, $leg2->prev_hash);
        $this->assertSame([], app(FxLedgerService::class)->verify($office, (int) now()->format('Y')));
    }

    public function test_cross_fields_are_protected_by_the_hash(): void
    {
        [, $session, $office] = $this->crossTill();
        $leg1 = $this->buyEurForUsd($session, '100');
        $leg2 = $leg1->pairLeg;

        foreach ([['leg', 1], ['pair_transaction_id', 999999], ['change_amount', '5.00']] as [$column, $value]) {
            \Illuminate\Support\Facades\DB::table('fx_transactions')->where('id', $leg2->id)->update([$column => $value]);
            $this->assertNotEmpty(app(FxLedgerService::class)->verify($office, (int) now()->format('Y')), "Ndryshimi i '{$column}' duhet zbuluar");
            \Illuminate\Support\Facades\DB::table('fx_transactions')->where('id', $leg2->id)->update([$column => $leg2->getRawOriginal($column)]);
        }

        $this->assertSame([], app(FxLedgerService::class)->verify($office, (int) now()->format('Y')));
    }

    public function test_voiding_either_leg_voids_the_whole_exchange(): void
    {
        [$till, $session] = $this->crossTill();
        $leg1 = $this->buyEurForUsd($session, '100');
        $leg2 = $leg1->pairLeg;

        // anulohet nga këmbisa 2 (p.sh. nga regjistri); anulohen të dyja, një arsye
        $reversal = app(FxCounterService::class)->void($leg2, 9100, 'klienti u tërhoq');

        $this->assertTrue($reversal->isReversal());
        $this->assertSame($leg1->id, (int) $reversal->voids_transaction_id, 'Kthehet anulimi i këmbisës 1');
        $this->assertSame(2, FxTransaction::query()->whereNotNull('voids_transaction_id')->count());
        $this->assertNotNull(FxTransaction::query()->find($leg1->id)->voidedBy);
        $this->assertNotNull(FxTransaction::query()->find($leg2->id)->voidedBy);

        $b = $this->balances($till);
        $this->assertSame('1000000.00', $b['ALL']);
        $this->assertSame('0.00', $b['EUR']);
        $this->assertSame('1000.00', $b['USD']);

        // anulimet e një kryqëzimi shfaqen si një rresht te sporteli: leg 1 + leg 2 (fshihet)
        $this->assertSame(2, $till->sessions()->first()->transactions()->notSecondLeg()->count(), 'kryqëzimi + anulimi = 2 rreshta');
    }

    public function test_a_cross_cannot_be_voided_twice_or_from_the_other_leg_afterwards(): void
    {
        [, $session] = $this->crossTill();
        $leg1 = $this->buyEurForUsd($session, '100');
        $leg2 = $leg1->pairLeg;
        app(FxCounterService::class)->void($leg1, 9100, 'gabim');

        foreach ([$leg1, $leg2] as $leg) {
            $this->refused(fn () => app(FxCounterService::class)->void($leg, 9100, 'përsëri'), 'reason');
        }
    }

    public function test_void_is_refused_when_the_returned_currency_is_no_longer_in_the_till(): void
    {
        [$till, $session] = $this->crossTill('1000000', '0', '1000');
        $leg1 = $this->buyEurForUsd($session, '100');

        // arka dorëzon 100 EUR te banka para anulimit → anulimi do të kërkonte EUR që s'ka më
        app(FxTillService::class)->movement($session, 'cash_out', 'EUR', '100', 'derdhje', 9100);

        $this->refused(fn () => app(FxCounterService::class)->void($leg1, 9100, 'gabim'), 'reason');
        $this->assertSame(0, FxTransaction::query()->whereNotNull('voids_transaction_id')->count());
    }

    public function test_cross_can_be_switched_off(): void
    {
        [, $session] = $this->crossTill();
        config(['plugins.emiron-fx.general.cross_enabled' => false]);

        $this->refused(fn () => $this->buyEurForUsd($session, '100'), 'pay_currency_id');
        $this->assertSame(0, FxTransaction::query()->count());
    }

    public function test_validation_rules(): void
    {
        [, $session] = $this->crossTill('1000000', '0', '50');

        // i njëjti monedhë
        $this->refused(fn () => app(FxCounterService::class)->execute($session, 9001, [
            'direction' => 'buy', 'currency_id' => $this->eur->id, 'pay_currency_id' => $this->eur->id, 'foreign_amount' => '10',
        ]), 'pay_currency_id');

        // monedhë pa kurs të publikuar
        $gbp = $this->currency('GBP');
        $this->refused(fn () => app(FxCounterService::class)->execute($session, 9001, [
            'direction' => 'buy', 'currency_id' => $this->eur->id, 'pay_currency_id' => $gbp->id, 'foreign_amount' => '10',
        ]), 'currency_id');

        // USD i pamjaftueshëm në arkë (108.69 USD nevojiten, ka 50)
        $this->refused(fn () => $this->buyEurForUsd($session, '100'), 'foreign_amount');

        // shumë zero / shumë e vogël (rezultati del zero)
        $this->refused(fn () => $this->buyEurForUsd($session, '0'), 'foreign_amount');
        $this->refused(fn () => $this->buyEurForUsd($session, '0.004'), 'foreign_amount');

        // vetëm pronari i seancës
        $this->refused(fn () => $this->buyEurForUsd($session, '10', [], 424242), 'foreign_amount');

        $this->assertSame(0, FxTransaction::query()->count(), 'Asnjë refuzim s\'lë gjurmë');
    }

    public function test_identification_threshold_applies_to_the_lek_value_of_the_exchange(): void
    {
        [, $session] = $this->crossTill('4000000', '0', '5000');

        // 1000 EUR × 100 = 100 000 Lek → i barabartë me pragun: lejohet pa klient
        $this->assertSame('1000.00', (string) $this->buyEurForUsd($session, '1000')->foreign_amount);

        // 1000.01 EUR → 100 001 Lek → kërkon klient të identifikuar
        $this->refused(fn () => $this->buyEurForUsd($session, '1000.01'), 'customer');
    }

    public function test_second_leg_is_not_counted_as_a_separate_operation(): void
    {
        [$till, $session] = $this->crossTill();
        $this->buyEurForUsd($session, '100');

        $all = $session->transactions()->count();
        $primary = $session->transactions()->notSecondLeg()->count();

        $this->assertSame(2, $all, 'dy këmbisa në regjistër');
        $this->assertSame(1, $primary, 'një veprim për sportelin');
    }

    public function test_quote_matches_the_executed_amounts(): void
    {
        [, $session] = $this->crossTill();
        $q = app(FxCrossService::class)->quote($this->eur, $this->usd, 'buy', '100');

        $this->assertSame('100.00', $q['a']);
        $this->assertSame('108.69', $q['b']);
        $this->assertSame('10000', $q['lek1']);
        $this->assertSame('9999', $q['lek2']);
        $this->assertSame('1.00', $q['change_lek']);
    }

    public function test_exchange_without_cross_columns_in_payload_still_uses_lek(): void
    {
        [$till, $session] = $this->crossTill();

        // pa pay_currency_id (ose bosh) → këmbim i zakonshëm me Lek
        $tx = app(FxCounterService::class)->execute($session, 9001, [
            'direction' => 'buy', 'currency_id' => $this->eur->id, 'pay_currency_id' => '', 'foreign_amount' => '10',
        ]);

        $this->assertNull($tx->leg);
        $this->assertSame('1000.00', (string) $tx->lek_amount);
        $this->assertSame(0, FxTransaction::query()->whereNotNull('pair_transaction_id')->count());
    }
}
