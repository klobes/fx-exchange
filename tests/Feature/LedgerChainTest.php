<?php

namespace Botble\EmironFx\Tests\Feature;

use Botble\EmironFx\Models\FxOfficialRate;
use Botble\EmironFx\Models\FxRate;
use Botble\EmironFx\Models\FxTransaction;
use Botble\EmironFx\Services\FxCounterService;
use Botble\EmironFx\Services\FxLedgerService;
use Botble\EmironFx\Tests\Support\FxTestCase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

/** Integriteti i regjistrit: verifikimi i zinxhirit, zbulimi i ndryshimeve jashtë aplikacionit, pandryshueshmëria në kod. */
class LedgerChainTest extends FxTestCase
{
    /** @return array{0:int,1:int,2:array<int,FxTransaction>} [office, year, [tx1, tx2, tx3]] */
    private function threeTransactions(): array
    {
        [, $session, $eur, $office] = $this->readyTill('1000000', '0');
        $counter = app(FxCounterService::class);
        $txs = [];

        foreach (['10', '20', '30'] as $amount) {
            $txs[] = $counter->execute($session, 9001, ['direction' => 'buy', 'currency_id' => $eur->id, 'foreign_amount' => $amount]);
        }

        return [$office, (int) now()->format('Y'), $txs];
    }

    public function test_an_untouched_chain_verifies_clean(): void
    {
        [$office, $year] = $this->threeTransactions();

        $this->assertSame([], app(FxLedgerService::class)->verify($office, $year));
    }

    public function test_a_chain_with_voids_still_verifies_clean(): void
    {
        [$office, $year, $txs] = $this->threeTransactions();
        app(FxCounterService::class)->void($txs[1], 9100, 'gabim');

        $this->assertSame([], app(FxLedgerService::class)->verify($office, $year));
    }

    public function test_changing_an_amount_outside_the_app_is_detected(): void
    {
        [$office, $year, $txs] = $this->threeTransactions();

        DB::table('fx_transactions')->where('id', $txs[1]->id)->update(['lek_amount' => '1.00']);

        $errors = app(FxLedgerService::class)->verify($office, $year);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString($txs[1]->number, implode(' ', $errors));
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function tamperedColumns(): array
    {
        return [
            'kursi' => ['rate', '1.000000'],
            'drejtimi' => ['direction', 'sell'],
            'data e biznesit' => ['business_date', '2020-01-01'],
            'monedha' => ['currency_code', 'USD'],
            'shuma në Lek' => ['lek_amount', '1.00'],
            'shuma valutë' => ['foreign_amount', '1.00'],
            'kursi BSH' => ['official_rate', '1.000000'],
            'numri' => ['number', 'Z9-2099-999999'],
        ];
    }

    #[DataProvider('tamperedColumns')]
    public function test_changing_a_hashed_column_outside_the_app_is_detected(string $column, string $value): void
    {
        [$office, $year, $txs] = $this->threeTransactions();
        $this->assertSame([], app(FxLedgerService::class)->verify($office, $year), 'Para ndryshimit zinxhiri është i pastër');

        DB::table('fx_transactions')->where('id', $txs[2]->id)->update([$column => $value]);

        $this->assertNotEmpty(app(FxLedgerService::class)->verify($office, $year), "Ndryshimi i '{$column}' duhet zbuluar");
    }

    public function test_breaking_the_previous_hash_link_is_detected(): void
    {
        [$office, $year, $txs] = $this->threeTransactions();

        DB::table('fx_transactions')->where('id', $txs[2]->id)->update(['prev_hash' => str_repeat('0', 64)]);

        $errors = app(FxLedgerService::class)->verify($office, $year);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Zinxhiri i këputur', implode(' ', $errors));
    }

    public function test_a_gap_in_the_numbering_is_detected(): void
    {
        [$office, $year, $txs] = $this->threeTransactions();

        DB::table('fx_transactions')->where('id', $txs[1]->id)->update(['seq' => 5]);

        $errors = app(FxLedgerService::class)->verify($office, $year);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Boshllëk', implode(' ', $errors));
    }

    public function test_chains_are_separate_per_office_and_year(): void
    {
        [$office, $year] = $this->threeTransactions();

        $this->assertSame([], app(FxLedgerService::class)->verify($office, $year + 1), 'Viti pa veprime është i pastër');
        $this->assertSame([], app(FxLedgerService::class)->verify($this->makeOffice('T2'), $year), 'Zyra pa veprime është e pastër');
    }

    public function test_orm_refuses_to_update_or_delete_ledger_rows(): void
    {
        [, , $txs] = $this->threeTransactions();

        $row = FxTransaction::query()->find($txs[0]->id);

        try {
            $row->update(['lek_amount' => '5.00']);
            $this->fail('Përditësimi i veprimit duhet refuzuar');
        } catch (LogicException $e) {
            $this->assertStringContainsString('i pandryshueshëm', $e->getMessage());
        }

        try {
            $row->delete();
            $this->fail('Fshirja e veprimit duhet refuzuar');
        } catch (LogicException $e) {
            $this->assertStringContainsString('i pandryshueshëm', $e->getMessage());
        }

        $this->assertSame('1000.00', (string) FxTransaction::query()->find($txs[0]->id)->lek_amount);
    }

    public function test_rate_rows_are_immutable_too(): void
    {
        $this->readyTill();

        $rate = FxRate::query()->first();

        $this->expectException(LogicException::class);
        $rate->update(['buy_rate' => '1']);
    }

    public function test_official_rate_rows_are_immutable_too(): void
    {
        $this->readyTill();

        $official = FxOfficialRate::query()->first();

        $this->expectException(LogicException::class);
        $official->delete();
    }

    /**
     * NJOHUR (shih plani 0.7/0.8): flags, rate_id, currency_id, cdd_reference s'janë në hash, ndaj ndryshimi i tyre
     * jashtë aplikacionit nuk zbulohet nga fx:verify-ledger. Hiqe këtë shënim kur të dalë hash-i i zgjeruar.
     */
    public function test_tampering_with_flags_is_detected(): void
    {
        $this->markTestIncomplete('Gabim i njohur: flags s\'hyn në hash — rregullohet me hash-in e zgjeruar (0.8.x).');
    }
}
