<?php

namespace Botble\EmironFx\Tests\Feature;

use Botble\EmironFx\Models\FxTillMovement;
use Botble\EmironFx\Models\FxTillSession;
use Botble\EmironFx\Services\FxTillService;
use Botble\EmironFx\Tests\Support\FxTestCase;
use Illuminate\Validation\ValidationException;

/** Hapja/mbyllja e arkës, diferencat me arsye, miratimi, furnizim/derdhje. */
class TillSessionTest extends FxTestCase
{
    private function svc(): FxTillService
    {
        return app(FxTillService::class);
    }

    private function assertRefused(callable $fn, string $key): void
    {
        try {
            $fn();
            $this->fail("Pritej ValidationException me çelës '{$key}'");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
        }
    }

    public function test_first_open_records_initial_balances_only_for_non_zero_counts(): void
    {
        $till = $this->makeTill($this->makeOffice());
        $this->currency('EUR');

        $session = $this->openTill($till, ['ALL' => '500000', 'EUR' => '0']);

        $this->assertSame(FxTillSession::STATUS_OPEN, $session->status);
        $moves = FxTillMovement::query()->where('till_id', $till->id)->get();
        $this->assertCount(1, $moves);
        $this->assertSame('initial', $moves[0]->type);
        $this->assertSame('ALL', $moves[0]->currency_code);
        $this->assertSame('500000.00', $this->balances($till)['ALL']);
    }

    public function test_cannot_open_a_second_session_while_one_is_open(): void
    {
        [$till] = $this->readyTill();

        $this->assertRefused(fn () => $this->openTill($till, ['ALL' => '1000000']), 'counts');
    }

    public function test_negative_counts_are_refused(): void
    {
        $till = $this->makeTill($this->makeOffice());

        $this->assertRefused(fn () => $this->openTill($till, ['ALL' => '-5']), 'counts.ALL');
    }

    public function test_close_with_matching_count_has_no_variance(): void
    {
        [$till, $session] = $this->readyTill('1000000', '0');

        $closed = $this->svc()->close($session, ['ALL' => '1000000', 'EUR' => '0'], null, 9001);

        $this->assertSame(FxTillSession::STATUS_CLOSED, $closed->status);
        $this->assertNotNull($closed->closed_at);
        $this->assertSame('1000000.00', $closed->closing_expected['ALL']);
        $this->assertFalse(FxTillMovement::query()->where('till_id', $till->id)->where('type', 'closing_variance')->exists());
    }

    public function test_close_with_a_difference_requires_a_note_and_records_the_variance(): void
    {
        [$till, $session] = $this->readyTill('1000000', '0');

        $this->assertRefused(fn () => $this->svc()->close($session, ['ALL' => '999900', 'EUR' => '0'], '  ', 9001), 'closing_note');
        $this->assertTrue($session->fresh()->isOpen(), 'Mbyllja e refuzuar s\'e mbyll seancën');

        $this->svc()->close($session, ['ALL' => '999900', 'EUR' => '0'], 'mungesë 100 Lek', 9001);

        $variance = FxTillMovement::query()->where('till_id', $till->id)->where('type', 'closing_variance')->first();
        $this->assertSame('ALL', $variance->currency_code);
        $this->assertSame('-100.00', (string) $variance->amount);
        $this->assertSame('mungesë 100 Lek', $variance->note);
        $this->assertSame('999900.00', $this->balances($till)['ALL'], 'Libri përputhet me numërimin pas diferencës');
    }

    public function test_cannot_close_twice(): void
    {
        [, $session] = $this->readyTill();
        $this->svc()->close($session, ['ALL' => '1000000', 'EUR' => '0'], null, 9001);

        $this->assertRefused(fn () => $this->svc()->close($session, ['ALL' => '1000000', 'EUR' => '0'], null, 9001), 'counts');
    }

    public function test_next_session_requires_approval_of_the_previous_closing(): void
    {
        [$till, $session] = $this->readyTill();
        $this->svc()->close($session, ['ALL' => '1000000', 'EUR' => '0'], null, 9001);

        $this->assertRefused(fn () => $this->openTill($till, ['ALL' => '1000000', 'EUR' => '0']), 'counts');

        $this->svc()->approve($session, 9100);
        $this->assertSame(FxTillSession::STATUS_APPROVED, $session->fresh()->status);
        $this->assertSame(9100, (int) $session->fresh()->approved_by);

        $next = $this->openTill($till, ['ALL' => '1000000', 'EUR' => '0']);
        $this->assertTrue($next->isOpen());
    }

    public function test_only_a_closed_session_can_be_approved(): void
    {
        [, $session] = $this->readyTill();

        $this->assertRefused(fn () => $this->svc()->approve($session, 9100), 'session');
    }

    public function test_opening_with_a_difference_requires_a_note_and_records_the_variance(): void
    {
        [$till, $session] = $this->readyTill('1000000', '0');
        $this->svc()->close($session, ['ALL' => '1000000', 'EUR' => '0'], null, 9001);
        $this->svc()->approve($session, 9100);

        $this->assertRefused(fn () => $this->openTill($till, ['ALL' => '1000500', 'EUR' => '0']), 'opening_note');

        $this->openTill($till, ['ALL' => '1000500', 'EUR' => '0'], 'teprica 500');
        $variance = FxTillMovement::query()->where('till_id', $till->id)->where('type', 'opening_variance')->first();
        $this->assertSame('500.00', (string) $variance->amount);
        $this->assertSame('1000500.00', $this->balances($till)['ALL']);
    }

    public function test_cash_out_cannot_exceed_the_balance_and_cash_in_adds(): void
    {
        [$till, $session] = $this->readyTill('1000000', '0');

        $this->assertRefused(fn () => $this->svc()->movement($session, 'cash_out', 'ALL', '2000000', 'derdhje', 9100), 'amount');

        $this->svc()->movement($session, 'cash_out', 'ALL', '400000', 'derdhje në bankë', 9100);
        $this->assertSame('600000.00', $this->balances($till)['ALL']);

        $this->svc()->movement($session, 'cash_in', 'ALL', '150000', 'furnizim nga banka', 9100);
        $this->assertSame('750000.00', $this->balances($till)['ALL']);
    }

    public function test_movement_amounts_must_be_positive_and_session_open(): void
    {
        [, $session] = $this->readyTill();

        $this->assertRefused(fn () => $this->svc()->movement($session, 'cash_in', 'ALL', '0', 'x', 9100), 'amount');
        $this->assertRefused(fn () => $this->svc()->movement($session, 'cash_in', 'ALL', '-5', 'x', 9100), 'amount');

        $this->svc()->close($session, ['ALL' => '1000000', 'EUR' => '0'], null, 9001);
        $this->assertRefused(fn () => $this->svc()->movement($session, 'cash_in', 'ALL', '10', 'x', 9100), 'amount');
    }

    public function test_one_cashier_has_one_till(): void
    {
        $office = $this->makeOffice();
        $this->makeTill($office, 9001, 'Arka A');

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->makeTill($office, 9001, 'Arka B'); // user_id unik
    }

    public function test_till_for_returns_only_the_active_till_of_the_user(): void
    {
        $office = $this->makeOffice();
        $till = $this->makeTill($office, 9001);

        $this->assertSame($till->id, $this->svc()->tillFor(9001)?->id);
        $this->assertNull($this->svc()->tillFor(424242));

        $till->forceFill(['is_active' => false])->save();
        $this->assertNull($this->svc()->tillFor(9001));
    }
}
