<?php

namespace Botble\EmironFx\Tests\Feature;

use Botble\EmironFx\Models\FxBankEntry;
use Botble\EmironFx\Models\FxPartnerEntry;
use Botble\EmironFx\Models\FxTillMovement;
use Botble\EmironFx\Services\FxBankService;
use Botble\EmironFx\Services\FxPartnerOpsService;
use Botble\EmironFx\Services\FxTillService;
use Botble\EmironFx\Tests\Support\FxTestCase;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/** Banka: lëvizjet, lidhja me arkën, konfirmimi, kundërveprimi, efekti te partneri. */
class BankTest extends FxTestCase
{
    private function bank(): FxBankService
    {
        return app(FxBankService::class);
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

    private function entry(array $data): FxBankEntry
    {
        return $this->bank()->record($data + ['entry_date' => now()->toDateString()], 9100);
    }

    public function test_balance_is_opening_plus_signed_entries(): void
    {
        $account = $this->makeBankAccount('ALL', '100000');

        $this->entry(['bank_account_id' => $account->id, 'type' => 'other_in', 'amount' => '5000', 'note' => 'kredi']);
        $this->entry(['bank_account_id' => $account->id, 'type' => 'other_out', 'amount' => '1200.50', 'note' => 'debi']);

        $this->assertSame('103799.50', $this->bank()->balance($account));
    }

    public function test_entry_amounts_are_signed_by_type(): void
    {
        $account = $this->makeBankAccount();
        $partner = $this->makePartner();

        $this->assertSame('-100.00', (string) $this->entry(['bank_account_id' => $account->id, 'type' => 'partner_prefund', 'amount' => '100', 'partner_id' => $partner])->amount);
        $this->assertSame('100.00', (string) $this->entry(['bank_account_id' => $account->id, 'type' => 'partner_reimbursement', 'amount' => '100', 'partner_id' => $partner])->amount);
        $this->assertSame('-100.00', (string) $this->entry(['bank_account_id' => $account->id, 'type' => 'partner_payment', 'amount' => '100', 'partner_id' => $partner])->amount);
        $this->assertSame('100.00', (string) $this->entry(['bank_account_id' => $account->id, 'type' => 'other_in', 'amount' => '100', 'note' => 'x'])->amount);
    }

    public function test_validation_of_basic_fields(): void
    {
        $account = $this->makeBankAccount();

        $this->refused(fn () => $this->entry(['bank_account_id' => $account->id, 'type' => 'invented', 'amount' => '10']), 'type');
        $this->refused(fn () => $this->entry(['bank_account_id' => $account->id, 'type' => 'other_in', 'amount' => '0', 'note' => 'x']), 'amount');
        $this->refused(fn () => $this->entry(['bank_account_id' => $account->id, 'type' => 'other_in', 'amount' => '-5', 'note' => 'x']), 'amount');
        $this->refused(fn () => $this->entry(['bank_account_id' => $account->id, 'type' => 'other_in', 'amount' => '10']), 'note');   // "të tjera" kërkon shënim
        $this->refused(fn () => $this->entry(['bank_account_id' => $account->id, 'type' => 'partner_prefund', 'amount' => '10']), 'partner_id');
    }

    public function test_an_inactive_account_cannot_receive_entries(): void
    {
        $account = $this->makeBankAccount('ALL', '0', false);

        $this->expectException(ModelNotFoundException::class);
        $this->entry(['bank_account_id' => $account->id, 'type' => 'other_in', 'amount' => '10', 'note' => 'x']);
    }

    public function test_deposit_is_linked_to_the_matching_cash_out_movement(): void
    {
        [$till, $session] = $this->readyTill();
        $movement = app(FxTillService::class)->movement($session, 'cash_out', 'ALL', '400000', 'derdhje', 9100);
        $account = $this->makeBankAccount('ALL', '0');

        $entry = $this->entry(['bank_account_id' => $account->id, 'type' => 'deposit_cash', 'amount' => '400000', 'till_movement_id' => $movement->id]);

        $this->assertSame($movement->id, (int) $entry->till_movement_id);
        $this->assertSame('400000.00', (string) $entry->amount);
        $this->assertSame('400000.00', $this->bank()->balance($account));
    }

    public function test_deposit_requires_exact_amount_currency_and_an_unlinked_cash_out(): void
    {
        [$till, $session] = $this->readyTill('1000000', '300');
        $out = app(FxTillService::class)->movement($session, 'cash_out', 'ALL', '400000', 'derdhje', 9100);
        $in = app(FxTillService::class)->movement($session, 'cash_in', 'ALL', '1000', 'furnizim', 9100);
        $all = $this->makeBankAccount('ALL');
        $eur = $this->makeBankAccount('EUR');

        // shumë e ndryshme
        $this->refused(fn () => $this->entry(['bank_account_id' => $all->id, 'type' => 'deposit_cash', 'amount' => '399999', 'till_movement_id' => $out->id]), 'amount');
        // monedhë e ndryshme e llogarisë
        $this->refused(fn () => $this->entry(['bank_account_id' => $eur->id, 'type' => 'deposit_cash', 'amount' => '400000', 'till_movement_id' => $out->id]), 'amount');
        // lëvizje e llojit të gabuar (cash_in për derdhje)
        $this->refused(fn () => $this->entry(['bank_account_id' => $all->id, 'type' => 'deposit_cash', 'amount' => '1000', 'till_movement_id' => $in->id]), 'till_movement_id');
        // pa lëvizje
        $this->refused(fn () => $this->entry(['bank_account_id' => $all->id, 'type' => 'deposit_cash', 'amount' => '400000']), 'till_movement_id');

        $this->entry(['bank_account_id' => $all->id, 'type' => 'deposit_cash', 'amount' => '400000', 'till_movement_id' => $out->id]);
        // e lidhur një herë: s'lidhet dy herë
        $this->refused(fn () => $this->entry(['bank_account_id' => $all->id, 'type' => 'deposit_cash', 'amount' => '400000', 'till_movement_id' => $out->id]), 'till_movement_id');
    }

    public function test_withdraw_cash_links_to_cash_in_and_reduces_the_account(): void
    {
        [, $session] = $this->readyTill();
        $in = app(FxTillService::class)->movement($session, 'cash_in', 'ALL', '250000', 'furnizim nga banka', 9100);
        $account = $this->makeBankAccount('ALL', '1000000');

        $entry = $this->entry(['bank_account_id' => $account->id, 'type' => 'withdraw_cash', 'amount' => '250000', 'till_movement_id' => $in->id]);

        $this->assertSame('-250000.00', (string) $entry->amount);
        $this->assertSame('750000.00', $this->bank()->balance($account));
    }

    public function test_confirmation_is_tracked_and_balance_can_be_viewed_confirmed_only(): void
    {
        $account = $this->makeBankAccount('ALL', '0');
        $a = $this->entry(['bank_account_id' => $account->id, 'type' => 'other_in', 'amount' => '1000', 'note' => 'a']);
        $this->entry(['bank_account_id' => $account->id, 'type' => 'other_in', 'amount' => '500', 'note' => 'b']);

        $this->assertSame('pending', $a->status);
        $this->bank()->confirm($a, '  EXT-001  ', 9100);

        $fresh = FxBankEntry::query()->find($a->id);
        $this->assertSame('confirmed', $fresh->status);
        $this->assertSame('EXT-001', $fresh->statement_ref);
        $this->assertSame(9100, (int) $fresh->confirmed_by);
        $this->assertNotNull($fresh->confirmed_at);
        $this->assertSame('1500.00', $this->bank()->balance($account));
        $this->assertSame('1000.00', $this->bank()->balance($account, true));
    }

    public function test_partner_entries_follow_bank_entries(): void
    {
        $account = $this->makeBankAccount('ALL', '1000000');
        $partner = $this->makePartner();
        $ops = app(FxPartnerOpsService::class);

        $this->entry(['bank_account_id' => $account->id, 'type' => 'partner_prefund', 'amount' => '50000', 'partner_id' => $partner]);
        $this->assertSame('50000.00', $ops->balances($partner)->get('ALL')['prepaid']);

        $this->entry(['bank_account_id' => $account->id, 'type' => 'partner_reimbursement', 'amount' => '10000', 'partner_id' => $partner]);
        $this->assertSame('-10000.00', $ops->balances($partner)->get('ALL')['receivable'], 'Rimbursimi zvogëlon detyrimin e partnerit');

        $this->entry(['bank_account_id' => $account->id, 'type' => 'partner_payment', 'amount' => '4000', 'partner_id' => $partner]);
        $this->assertSame('-6000.00', $ops->balances($partner)->get('ALL')['receivable']);
    }

    public function test_reversal_negates_the_entry_and_its_partner_effect(): void
    {
        $account = $this->makeBankAccount('ALL', '1000000');
        $partner = $this->makePartner();
        $original = $this->entry(['bank_account_id' => $account->id, 'type' => 'partner_prefund', 'amount' => '50000', 'partner_id' => $partner]);

        $reversal = $this->bank()->reverse($original, 'transfertë e gabuar', 9100);

        $this->assertSame('reversal', $reversal->type);
        $this->assertSame('50000.00', (string) $reversal->amount);
        $this->assertSame($original->id, (int) $reversal->reverses_entry_id);
        $this->assertSame('1000000.00', $this->bank()->balance($account));
        $this->assertSame('0.00', app(FxPartnerOpsService::class)->balances($partner)->get('ALL')['prepaid']);
        $this->assertSame(1, FxBankEntry::query()->where('id', $original->id)->count(), 'Origjinali nuk fshihet');
        $this->assertSame(2, FxPartnerEntry::query()->where('partner_id', $partner)->count());
    }

    public function test_reversal_rules(): void
    {
        $account = $this->makeBankAccount();
        $original = $this->entry(['bank_account_id' => $account->id, 'type' => 'other_in', 'amount' => '10', 'note' => 'x']);

        $this->refused(fn () => $this->bank()->reverse($original, 'abc', 9100), 'reason');          // arsye shumë e shkurtër
        $reversal = $this->bank()->reverse($original, 'arsye e plotë', 9100);
        $this->refused(fn () => $this->bank()->reverse($original, 'arsye e plotë', 9100), 'reason'); // dy herë
        $this->refused(fn () => $this->bank()->reverse($reversal, 'arsye e plotë', 9100), 'reason'); // anulim i anulimit
    }

    public function test_reversing_a_cash_linked_entry_keeps_the_till_movement_in_the_ledger(): void
    {
        [, $session] = $this->readyTill();
        $out = app(FxTillService::class)->movement($session, 'cash_out', 'ALL', '1000', 'derdhje', 9100);
        $account = $this->makeBankAccount('ALL');
        $wrong = $this->entry(['bank_account_id' => $account->id, 'type' => 'deposit_cash', 'amount' => '1000', 'till_movement_id' => $out->id]);

        $this->bank()->reverse($wrong, 'llogari e gabuar', 9100);

        // lëvizja e arkës mbetet e lidhur te rreshti i parë (kolonë unike); asgjë s'fshihet, balanca e bankës kthehet në zero
        $this->assertSame(1, FxBankEntry::query()->where('till_movement_id', $out->id)->count());
        $this->assertSame('0.00', $this->bank()->balance($account));
        $this->assertNotNull(FxTillMovement::query()->find($out->id), 'Lëvizja e arkës mbetet në libër');
    }
}
