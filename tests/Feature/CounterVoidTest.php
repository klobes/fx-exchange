<?php

namespace Botble\EmironFx\Tests\Feature;

use Botble\EmironFx\Models\FxTransaction;
use Botble\EmironFx\Services\FxCounterService;
use Botble\EmironFx\Services\FxTillService;
use Botble\EmironFx\Tests\Support\FxTestCase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** Anulimi: veprim i kundërt me numër të ri; rregullat e ditës, seancës, arsyes dhe gjendjes së arkës. */
class CounterVoidTest extends FxTestCase
{
    private function tx(string $direction, $eur, string $amount, $session): FxTransaction
    {
        return app(FxCounterService::class)->execute($session, 9001, [
            'direction' => $direction, 'currency_id' => $eur->id, 'foreign_amount' => $amount,
        ]);
    }

    private function void(FxTransaction $tx, string $reason = 'gabim në shumë'): FxTransaction
    {
        return app(FxCounterService::class)->void($tx, 9100, $reason);
    }

    private function assertReasonRefused(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Pritej ValidationException te "reason"');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }
    }

    public function test_voiding_a_purchase_restores_the_till_and_keeps_the_original(): void
    {
        [$till, $session, $eur] = $this->readyTill('1000000', '0');
        $original = $this->tx(FxTransaction::BUY, $eur, '100', $session);

        $reversal = $this->void($original, 'klienti u tërhoq');

        $this->assertTrue($reversal->isReversal());
        $this->assertSame($original->id, (int) $reversal->voids_transaction_id);
        $this->assertSame(2, (int) $reversal->seq);
        $this->assertSame('klienti u tërhoq', $reversal->void_reason);
        $this->assertSame($original->hash, $reversal->prev_hash);

        $b = $this->balances($till);
        $this->assertSame('1000000.00', $b['ALL']);
        $this->assertSame('0.00', $b['EUR']);

        // origjinali mbetet siç u regjistrua
        $fresh = FxTransaction::query()->find($original->id);
        $this->assertSame($original->hash, $fresh->hash);
        $this->assertFalse($fresh->isReversal());
        $this->assertNotNull($fresh->voidedBy);
    }

    public function test_voiding_a_sale_restores_both_currencies(): void
    {
        [$till, $session, $eur] = $this->readyTill('1000000', '300');
        $sale = $this->tx(FxTransaction::SELL, $eur, '100', $session);

        $this->void($sale);

        $b = $this->balances($till);
        $this->assertSame('1000000.00', $b['ALL']);
        $this->assertSame('300.00', $b['EUR']);
    }

    public function test_a_transaction_cannot_be_voided_twice(): void
    {
        [, $session, $eur] = $this->readyTill();
        $original = $this->tx(FxTransaction::BUY, $eur, '10', $session);
        $this->void($original);

        $this->assertReasonRefused(fn () => $this->void($original));
    }

    public function test_a_reversal_cannot_be_voided(): void
    {
        [, $session, $eur] = $this->readyTill();
        $reversal = $this->void($this->tx(FxTransaction::BUY, $eur, '10', $session));

        $this->assertReasonRefused(fn () => $this->void($reversal));
    }

    public function test_a_reason_is_mandatory(): void
    {
        [, $session, $eur] = $this->readyTill();
        $original = $this->tx(FxTransaction::BUY, $eur, '10', $session);

        $this->assertReasonRefused(fn () => $this->void($original, '   '));
        $this->assertFalse(FxTransaction::query()->where('voids_transaction_id', $original->id)->exists());
    }

    public function test_voiding_after_the_session_is_closed_is_refused(): void
    {
        [$till, $session, $eur] = $this->readyTill('1000000', '0');
        $original = $this->tx(FxTransaction::BUY, $eur, '10', $session);

        app(FxTillService::class)->close($session, ['ALL' => '999000', 'EUR' => '10'], null, 9001);

        $this->assertReasonRefused(fn () => $this->void($original));
    }

    public function test_voiding_on_another_day_is_refused(): void
    {
        [, $session, $eur] = $this->readyTill();
        $original = $this->tx(FxTransaction::BUY, $eur, '10', $session);

        Carbon::setTestNow(now()->addDay());

        $this->assertReasonRefused(fn () => $this->void($original));
    }

    public function test_voiding_a_purchase_is_refused_if_the_currency_was_sold_in_the_meantime(): void
    {
        [, $session, $eur] = $this->readyTill('1000000', '0');
        $buy = $this->tx(FxTransaction::BUY, $eur, '100', $session);
        $this->tx(FxTransaction::SELL, $eur, '100', $session); // arka s'ka më EUR

        $this->assertReasonRefused(fn () => $this->void($buy));
    }
}
