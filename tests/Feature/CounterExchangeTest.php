<?php

namespace Botble\EmironFx\Tests\Feature;

use Botble\EmironFx\Models\FxTransaction;
use Botble\EmironFx\Services\FxCounterService;
use Botble\EmironFx\Tests\Support\FxTestCase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Veprimet e këmbimit në sportel: blerje/shitje kundrejt Lekut, pragjet, refuzimet, flamujt.
 * Konventa: buy_rate = zyra BLEN valutën (klienti shet), sell_rate = zyra SHET.
 */
class CounterExchangeTest extends FxTestCase
{
    private function exchange(string $direction, $currency, string $amount, array $extra = [], $session = null, int $userId = 9001): FxTransaction
    {
        return app(FxCounterService::class)->execute($session, $userId, [
            'direction' => $direction,
            'currency_id' => $currency->id,
            'foreign_amount' => $amount,
        ] + $extra);
    }

    private function assertValidationKey(callable $fn, string $key): void
    {
        try {
            $fn();
            $this->fail("Pritej ValidationException me çelës '{$key}'");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
        }
    }

    public function test_buy_foreign_currency_pays_lek_at_buy_rate(): void
    {
        [$till, $session, $eur] = $this->readyTill('1000000', '0');

        $tx = $this->exchange(FxTransaction::BUY, $eur, '100', [], $session);

        $this->assertSame('buy', $tx->direction);
        $this->assertSame('10000.00', (string) $tx->lek_amount);
        $this->assertSame('100.00', (string) $tx->foreign_amount);
        $this->assertSame('100.000000', (string) $tx->rate);
        $this->assertSame('101.000000', (string) $tx->official_rate);
        $this->assertSame('99.01', (string) $tx->eur_equivalent); // 10000 ÷ 101

        $b = $this->balances($till);
        $this->assertSame('990000.00', $b['ALL']);
        $this->assertSame('100.00', $b['EUR']);
    }

    public function test_sell_foreign_currency_receives_lek_at_sell_rate(): void
    {
        [$till, $session, $eur] = $this->readyTill('1000000', '500');

        $tx = $this->exchange(FxTransaction::SELL, $eur, '50', [], $session);

        $this->assertSame('5100.00', (string) $tx->lek_amount); // 50 × 102
        $b = $this->balances($till);
        $this->assertSame('1005100.00', $b['ALL']);
        $this->assertSame('450.00', $b['EUR']);
    }

    public function test_unit_100_currency_uses_rate_per_hundred(): void
    {
        [$till, $session] = $this->readyTill();
        $jpy = $this->currency('JPY', 100, 0);
        $this->setOfficial($jpy, '62');
        $this->setRate($jpy, '62.5', '64');
        // arka duhet të njohë JPY: mbyll/rihap s'është e nevojshme, gjendja JPY krijohet nga blerja
        $tx = $this->exchange(FxTransaction::BUY, $jpy, '10000', [], $session);

        $this->assertSame('6250.00', (string) $tx->lek_amount); // 10000 × 62.5 ÷ 100
        $this->assertSame('10000.00', (string) $tx->foreign_amount);
    }

    public function test_foreign_amount_is_rounded_to_currency_decimals(): void
    {
        [, $session, , ] = $this->readyTill();
        $jpy = $this->currency('JPY', 100, 0);
        $this->setOfficial($jpy, '62');
        $this->setRate($jpy, '62.5', '64');

        $tx = $this->exchange(FxTransaction::BUY, $jpy, '1000.6', [], $session);

        $this->assertSame('1001.00', (string) $tx->foreign_amount); // JPY pa dhjetore
    }

    public function test_sell_is_refused_when_till_lacks_the_currency(): void
    {
        [$till, $session, $eur] = $this->readyTill('1000000', '100');
        $before = FxTransaction::query()->count();

        $this->assertValidationKey(fn () => $this->exchange(FxTransaction::SELL, $eur, '500', [], $session), 'foreign_amount');

        $this->assertSame($before, FxTransaction::query()->count(), 'Veprimi i refuzuar s\'duhet të lërë gjurmë');
        $this->assertSame('100.00', $this->balances($till)['EUR']);
    }

    public function test_buy_is_refused_when_till_lacks_lek(): void
    {
        [, $session, $eur] = $this->readyTill('5000', '0');

        $this->assertValidationKey(fn () => $this->exchange(FxTransaction::BUY, $eur, '100', [], $session), 'foreign_amount'); // duhen 10 000 Lek
    }

    public function test_zero_and_negative_amounts_are_refused(): void
    {
        [, $session, $eur] = $this->readyTill();

        $this->assertValidationKey(fn () => $this->exchange(FxTransaction::BUY, $eur, '0', [], $session), 'foreign_amount');
        $this->assertValidationKey(fn () => $this->exchange(FxTransaction::BUY, $eur, 'abc', [], $session), 'foreign_amount');
    }

    public function test_inactive_currency_is_refused(): void
    {
        [, $session] = $this->readyTill();
        $chf = $this->currency('CHF', 1, 2, false);

        $this->assertValidationKey(fn () => $this->exchange(FxTransaction::BUY, $chf, '10', [], $session), 'currency_id');
    }

    public function test_currency_without_published_rate_is_refused(): void
    {
        [, $session] = $this->readyTill();
        $gbp = $this->currency('GBP');

        $this->assertValidationKey(fn () => $this->exchange(FxTransaction::BUY, $gbp, '10', [], $session), 'currency_id');
    }

    public function test_only_the_session_owner_can_transact(): void
    {
        [, $session, $eur] = $this->readyTill();

        $this->assertValidationKey(fn () => $this->exchange(FxTransaction::BUY, $eur, '10', [], $session, 77777), 'foreign_amount');
    }

    public function test_identification_is_required_only_above_100000_lek(): void
    {
        [, $session, $eur] = $this->readyTill('4000000', '0'); // nën kufirin e gjendjes së subjektit (5 000 000)

        // 1000 EUR × 100 = 100 000 Lek → i barabartë me pragun: lejohet pa klient (kufiri është ">")
        $ok = $this->exchange(FxTransaction::BUY, $eur, '1000', [], $session);
        $this->assertSame('100000.00', (string) $ok->lek_amount);

        // 1000.01 EUR → 100 001 Lek → kërkon klient të identifikuar
        $this->assertValidationKey(fn () => $this->exchange(FxTransaction::BUY, $eur, '1000.01', [], $session), 'customer');
    }

    public function test_holding_limit_blocks_a_supply_that_reaches_the_limit(): void
    {
        config(['plugins.emiron-fx.general.holding_limit_lek' => '2000000']);
        [$till, $session] = $this->readyTill('1900000', '0');

        // 1 900 000 + 200 000 = 2 100 000 ≥ kufiri (kufiri është "më e vogël se")
        $this->assertValidationKey(
            fn () => app(\Botble\EmironFx\Services\FxTillService::class)->movement($session, 'cash_in', 'ALL', '200000', 'test', 9001),
            'amount'
        );
        $this->assertSame('1900000.00', $this->balances($till)['ALL']);
    }

    public function test_stale_rate_flag_is_set_only_when_rate_was_published_before_today(): void
    {
        [, $session, $eur] = $this->readyTill();

        $fresh = $this->exchange(FxTransaction::BUY, $eur, '10', [], $session);
        $this->assertNotContains('stale_rate', (array) $fresh->flags);

        // kurs i publikuar dje → paralajmërim (nuk bllokon)
        $this->setRate($eur, '100', '102', now()->subDay());
        // kursi në fuqi është MAX(id) me effective_at ≤ tani: rreshti i ri (id më i madh) është i djeshmi
        $stale = $this->exchange(FxTransaction::BUY, $eur, '10', [], $session);
        $this->assertContains('stale_rate', (array) $stale->flags);
    }

    public function test_stale_warning_can_be_switched_off(): void
    {
        config(['plugins.emiron-fx.general.rate_stale_warning' => false]);
        [, $session, $eur] = $this->readyTill();
        $this->setRate($eur, '100', '102', now()->subDay());

        $tx = $this->exchange(FxTransaction::BUY, $eur, '10', [], $session);
        $this->assertNotContains('stale_rate', (array) $tx->flags);
    }

    public function test_transaction_numbers_are_gapless_and_chain_is_linked(): void
    {
        [, $session, $eur] = $this->readyTill('1000000', '0');
        $year = (int) now()->format('Y');

        $a = $this->exchange(FxTransaction::BUY, $eur, '10', [], $session);
        $b = $this->exchange(FxTransaction::BUY, $eur, '20', [], $session);
        $c = $this->exchange(FxTransaction::BUY, $eur, '30', [], $session);

        $this->assertSame([1, 2, 3], [(int) $a->seq, (int) $b->seq, (int) $c->seq]);
        $this->assertSame(sprintf('T1-%d-000001', $year), $a->number);
        $this->assertNull($a->prev_hash);
        $this->assertSame($a->hash, $b->prev_hash);
        $this->assertSame($b->hash, $c->prev_hash);
    }

    public function test_cash_movements_follow_the_transaction_for_both_currencies(): void
    {
        [$till, $session, $eur] = $this->readyTill('1000000', '0');

        $tx = $this->exchange(FxTransaction::BUY, $eur, '100', [], $session);
        $moves = \Botble\EmironFx\Models\FxTillMovement::query()->where('transaction_id', $tx->id)->get()->pluck('amount', 'currency_code');

        $this->assertSame('100.00', (string) $moves['EUR']);
        $this->assertSame('-10000.00', (string) $moves['ALL']);
        $this->assertSame('transaction', \Botble\EmironFx\Models\FxTillMovement::query()->where('transaction_id', $tx->id)->value('type'));
    }
}
