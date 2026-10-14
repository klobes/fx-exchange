<?php

namespace Botble\EmironFx\Tests\Feature;

use Botble\EmironFx\Models\FxTransaction;
use Botble\EmironFx\Services\FxCounterService;
use Botble\EmironFx\Services\FxPnlService;
use Botble\EmironFx\Services\FxTillService;
use Botble\EmironFx\Tests\Support\FxTestCase;

/**
 * Fitimi/humbja reale (0.7.x: kosto mesatare e ponderuar). KUJDES: plani vendos kalimin në FIFO (0.8.x);
 * kur të ndryshojë metoda, rastet me më shumë se një blerje me kurse të ndryshme duhet përditësuar.
 */
class PnlTest extends FxTestCase
{
    private function exchange(string $direction, $eur, string $amount, $session): FxTransaction
    {
        return app(FxCounterService::class)->execute($session, 9001, [
            'direction' => $direction, 'currency_id' => $eur->id, 'foreign_amount' => $amount,
        ]);
    }

    private function pnl(): array
    {
        return app(FxPnlService::class)->compute(now(), now());
    }

    public function test_realized_profit_and_unrealized_value_for_a_buy_then_partial_sell(): void
    {
        [, $session, $eur] = $this->readyTill('1000000', '0');   // BSH 101, blerje 100, shitje 102

        $this->exchange(FxTransaction::BUY, $eur, '100', $session);  // kosto 10 000 Lek
        $this->exchange(FxTransaction::SELL, $eur, '40', $session);  // të ardhura 4 080, kosto 4 000

        $r = $this->pnl();

        $this->assertSame('60.00', $r['rows']['EUR']['qty']);
        $this->assertSame('100.0000', $r['rows']['EUR']['avg']);
        $this->assertSame('80', $r['rows']['EUR']['realized']);        // 4080 − 4000
        $this->assertSame('60', $r['rows']['EUR']['unrealized']);      // 60 × 101 − 6000
        $this->assertSame('6060', $r['rows']['EUR']['stock_value']);
        $this->assertSame('80', $r['totals']['realized']);
        $this->assertSame('80', $r['totals']['net']);
    }

    public function test_weighted_average_cost_across_two_purchases(): void
    {
        [, $session, $eur] = $this->readyTill('1000000', '0');

        $this->exchange(FxTransaction::BUY, $eur, '100', $session);  // 100 @100 = 10 000
        $this->setRate($eur, '110', '112');                         // kurs i ri (rreshti i fundit në fuqi)
        $this->exchange(FxTransaction::BUY, $eur, '100', $session);  // 100 @110 = 11 000 → mesatarja 105
        $this->exchange(FxTransaction::SELL, $eur, '100', $session); // 100 @112 = 11 200, kosto 10 500

        $r = $this->pnl();

        $this->assertSame('100.00', $r['rows']['EUR']['qty']);
        $this->assertSame('105.0000', $r['rows']['EUR']['avg']);
        $this->assertSame('700', $r['rows']['EUR']['realized']);       // 11 200 − 10 500
    }

    public function test_a_voided_pair_has_no_effect_on_stock_or_profit(): void
    {
        [, $session, $eur] = $this->readyTill('1000000', '0');
        $buy = $this->exchange(FxTransaction::BUY, $eur, '100', $session);
        app(FxCounterService::class)->void($buy, 9100, 'gabim');

        $r = $this->pnl();

        $this->assertSame('0.00', $r['rows']['EUR']['qty'] ?? '0.00');
        $this->assertSame('0', $r['rows']['EUR']['realized'] ?? '0');
        $this->assertSame('0', $r['totals']['net']);
    }

    public function test_closing_shortage_in_foreign_currency_is_valued_at_average_cost(): void
    {
        [, $session, $eur] = $this->readyTill('1000000', '0');
        $this->exchange(FxTransaction::BUY, $eur, '100', $session);  // 100 EUR @100
        $this->exchange(FxTransaction::SELL, $eur, '40', $session);  // mbeten 60

        // Lek i pritur = 1 000 000 − 10 000 + 4 080 = 994 080; numërohen 59 EUR (mungon 1 EUR)
        app(FxTillService::class)->close($session, ['ALL' => '994080', 'EUR' => '59'], 'mungesë 1 EUR', 9001);

        $r = $this->pnl();

        $this->assertSame('-100', $r['rows']['EUR']['variance']);  // 1 EUR × kosto mesatare 100
        $this->assertSame('59.00', $r['rows']['EUR']['qty']);
        $this->assertSame('-100', $r['totals']['variance']);
        $this->assertSame('-20', $r['totals']['net']);             // 80 realized − 100 variance
    }

    public function test_lek_shortage_is_reported_at_nominal_value(): void
    {
        [, $session] = $this->readyTill('1000000', '0');

        app(FxTillService::class)->close($session, ['ALL' => '999950', 'EUR' => '0'], 'mungesë 50 Lek', 9001);

        $r = $this->pnl();

        $this->assertSame('-50', $r['totals']['lek_variance']);
        $this->assertSame('-50', $r['totals']['variance']);
        $this->assertSame('-50', $r['totals']['net']);
    }

    public function test_no_activity_gives_zero_net(): void
    {
        $this->readyTill();

        $r = $this->pnl();

        $this->assertSame('0', $r['totals']['net']);
        $this->assertSame('0', $r['totals']['realized']);
    }
}
