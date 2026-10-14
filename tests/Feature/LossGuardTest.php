<?php

namespace Botble\EmironFx\Tests\Feature;

use Botble\EmironFx\Models\FxCurrency;
use Botble\EmironFx\Models\FxTillSession;
use Botble\EmironFx\Models\FxTransaction;
use Botble\EmironFx\Services\FxCounterService;
use Botble\EmironFx\Services\LossGuardService;
use Botble\EmironFx\Tests\Support\FxTestCase;
use Illuminate\Validation\ValidationException;

/**
 * Mbrojtja nga humbja në shitje (0.8.4).
 *
 * Zyra blen 100 EUR me 100 Lek (kosto 100), pastaj kursi bie: blerje 95 / shitje 97 → shitja me 97 është nën kosto.
 */
class LossGuardTest extends FxTestCase
{
    private FxCurrency $eur;

    private function stockedSession(): FxTillSession
    {
        $office = $this->makeOffice();
        $till = $this->makeTill($office);
        $this->eur = $this->currency('EUR');
        $this->setOfficial($this->eur, '101');
        $this->setRate($this->eur, '100', '102', now()->subMinutes(10));
        $session = $this->openTill($till, ['ALL' => '1000000', 'EUR' => '0']);

        // blerje 100 EUR @100 → kosto mesatare 100
        app(FxCounterService::class)->execute($session, 9001, [
            'direction' => FxTransaction::BUY, 'currency_id' => $this->eur->id, 'foreign_amount' => '100',
        ]);

        // kursi bie nën koston
        $this->setRate($this->eur, '95', '97', now()->subMinutes(5));

        return $session;
    }

    private function sell(FxTillSession $session, string $amount, array $extra = []): FxTransaction
    {
        return app(FxCounterService::class)->execute($session, 9001, [
            'direction' => FxTransaction::SELL, 'currency_id' => $this->eur->id, 'foreign_amount' => $amount,
        ] + $extra);
    }

    public function test_block_mode_refuses_sale_below_cost(): void
    {
        config(['plugins.emiron-fx.general.loss_guard_mode' => 'block']);
        $session = $this->stockedSession();

        $this->expectException(ValidationException::class);
        $this->sell($session, '10');
    }

    public function test_block_mode_allows_when_rate_is_at_or_above_cost(): void
    {
        config(['plugins.emiron-fx.general.loss_guard_mode' => 'block']);
        $session = $this->stockedSession();
        $this->setRate($this->eur, '99', '101', now()->subMinute());

        $tx = $this->sell($session, '10');

        $this->assertNotContains('below_cost', (array) $tx->flags);
    }

    public function test_warn_mode_allows_and_flags(): void
    {
        config(['plugins.emiron-fx.general.loss_guard_mode' => 'warn']);
        $session = $this->stockedSession();

        $tx = $this->sell($session, '10');

        $this->assertContains('below_cost', (array) $tx->flags);
    }

    public function test_off_mode_ignores_cost(): void
    {
        config(['plugins.emiron-fx.general.loss_guard_mode' => 'off']);
        $session = $this->stockedSession();

        $tx = $this->sell($session, '10');

        $this->assertNotContains('below_cost', (array) $tx->flags);
    }

    public function test_no_stock_cost_means_no_guard(): void
    {
        config(['plugins.emiron-fx.general.loss_guard_mode' => 'block']);
        $office = $this->makeOffice();
        $till = $this->makeTill($office);
        $this->eur = $this->currency('EUR');
        $this->setOfficial($this->eur, '101');
        $this->setRate($this->eur, '100', '102');
        $session = $this->openTill($till, ['ALL' => '1000000', 'EUR' => '500']);

        $tx = $this->sell($session, '10');

        $this->assertNotContains('below_cost', (array) $tx->flags);
    }

    public function test_override_needs_permission_flag_and_reason(): void
    {
        config(['plugins.emiron-fx.general.loss_guard_mode' => 'block']);
        $guard = app(LossGuardService::class);
        $eval = ['below' => true, 'cost' => '100', 'rate' => '97', 'loss' => '30', 'code' => 'EUR'];

        // pa leje → refuzohet edhe me kërkesë
        try {
            $guard->enforce($eval, false, true, 'klient i rregullt');
            $this->fail('Pritej refuzim pa leje');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('foreign_amount', $e->errors());
        }

        // me leje por pa arsye
        try {
            $guard->enforce($eval, true, true, 'ok');
            $this->fail('Pritej refuzim pa arsye');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('below_cost_reason', $e->errors());
        }

        // me leje + kërkesë + arsye → kalon me flamur
        $this->assertSame('below_cost', $guard->enforce($eval, true, true, 'klient i rregullt'));
    }
}
