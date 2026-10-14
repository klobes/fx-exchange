<?php

namespace Botble\EmironFx\Tests\Feature;

use Botble\EmironFx\Models\FxPartnerOperation;
use Botble\EmironFx\Services\FxBankService;
use Botble\EmironFx\Services\FxCounterService;
use Botble\EmironFx\Services\FxLedgerService;
use Botble\EmironFx\Services\FxPartnerOpsService;
use Botble\EmironFx\Services\FxTillService;
use Botble\EmironFx\Tests\Support\FxTestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Veprimet me partnerët: shërbime IN/OUT, gjendja e parapaguar, llogaria me partnerin, anulimi, zinxhiri "P".
 * Shërbimet e testit krijohen këtu (s'varen nga të dhënat fillestare të migrimit).
 */
class PartnerOperationsTest extends FxTestCase
{
    private function ops(): FxPartnerOpsService
    {
        return app(FxPartnerOpsService::class);
    }

    private function run_(callable $fn, string $key): void
    {
        try {
            $fn();
            $this->fail("Pritej ValidationException me çelës '{$key}'");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
        }
    }

    private function exec($session, int $serviceId, string $currency, string $amount, ?string $reference = null, int $userId = 9001): FxPartnerOperation
    {
        return $this->ops()->execute($session, $userId, [
            'service_id' => $serviceId, 'currency_code' => $currency, 'amount' => $amount, 'reference' => $reference,
        ]);
    }

    /** Mbushje e gjendjes së parapaguar përmes bankës (siç bën admini). */
    private function prefund(int $partnerId, string $amount): void
    {
        $account = $this->makeBankAccount('ALL', '5000000');
        app(FxBankService::class)->record([
            'bank_account_id' => $account->id, 'type' => 'partner_prefund', 'entry_date' => now()->toDateString(),
            'amount' => $amount, 'partner_id' => $partnerId, 'reference' => 'transfertë testi',
        ], 9100);
    }

    public function test_prefunded_payment_is_refused_without_prepaid_balance(): void
    {
        [$till, $session] = $this->readyTill();
        $partner = $this->makePartner();
        $light = $this->makeService($partner, 'Energji elektrike', 'in', 'prefunded');

        $this->run_(fn () => $this->exec($session, $light, 'ALL', '5000'), 'p_amount');

        $this->assertSame('1000000.00', $this->balances($till)['ALL'], 'Refuzimi s\'lë gjurmë në arkë');
        $this->assertSame(0, FxPartnerOperation::query()->count());
    }

    public function test_prefunded_payment_takes_cash_in_and_uses_the_prepaid_balance(): void
    {
        [$till, $session] = $this->readyTill();
        $partner = $this->makePartner();
        $light = $this->makeService($partner, 'Energji elektrike', 'in', 'prefunded');
        $this->prefund($partner, '20000');

        $op = $this->exec($session, $light, 'ALL', '5000', 'fatura 123');

        $this->assertSame('5000.00', (string) $op->amount);
        $this->assertSame('in', $op->direction);
        $this->assertSame('fatura 123', $op->reference);
        $this->assertSame(sprintf('T1-P-%d-000001', now()->year), $op->number);
        $this->assertSame('1005000.00', $this->balances($till)['ALL']);
        $this->assertSame('15000.00', $this->ops()->balances($partner)->get('ALL')['prepaid']);
        $this->assertSame('0.00', $this->ops()->balances($partner)->get('ALL')['receivable']);
    }

    public function test_prepaid_balance_must_cover_the_whole_payment(): void
    {
        [, $session] = $this->readyTill();
        $partner = $this->makePartner();
        $light = $this->makeService($partner, 'Ujësjellës', 'in', 'prefunded');
        $this->prefund($partner, '3000');

        $this->run_(fn () => $this->exec($session, $light, 'ALL', '3000.01'), 'p_amount');
        $this->exec($session, $light, 'ALL', '3000'); // saktësisht sa ka: lejohet
        $this->assertSame('0.00', $this->ops()->balances($partner)->get('ALL')['prepaid']);
    }

    public function test_out_service_pays_cash_out_and_creates_a_receivable_from_the_partner(): void
    {
        [$till, $session] = $this->readyTill('1000000', '500');
        $partner = $this->makePartner();
        $mg = $this->makeService($partner, 'MoneyGram — tërheqje', 'out', 'account', ['ALL', 'EUR']);

        $op = $this->exec($session, $mg, 'EUR', '100', 'MTCN 555');

        $this->assertSame('out', $op->direction);
        $this->assertSame('400.00', $this->balances($till)['EUR']);
        $bal = $this->ops()->balances($partner)->get('EUR');
        $this->assertSame('100.00', $bal['receivable'], 'Partneri na detyrohet 100 EUR');
        $this->assertSame('0.00', $bal['prepaid']);
    }

    public function test_out_service_is_refused_when_the_till_lacks_cash(): void
    {
        [, $session] = $this->readyTill('1000000', '50');
        $partner = $this->makePartner();
        $mg = $this->makeService($partner, 'MoneyGram — tërheqje', 'out', 'account', ['EUR']);

        $this->run_(fn () => $this->exec($session, $mg, 'EUR', '100'), 'p_amount');
    }

    public function test_in_service_on_account_creates_a_payable_to_the_partner(): void
    {
        [$till, $session] = $this->readyTill();
        $partner = $this->makePartner();
        $send = $this->makeService($partner, 'MoneyGram — dërgesë', 'in', 'account', ['ALL']);

        $this->exec($session, $send, 'ALL', '30000');

        $this->assertSame('1030000.00', $this->balances($till)['ALL']);
        $this->assertSame('-30000.00', $this->ops()->balances($partner)->get('ALL')['receivable'], 'Ne i detyrohemi partnerit');
    }

    public function test_service_validation_rules(): void
    {
        [, $session] = $this->readyTill();
        $partner = $this->makePartner();
        $light = $this->makeService($partner, 'Drita', 'in', 'prefunded', ['ALL']);
        $off = $this->makeService($partner, 'E çaktivizuar', 'in', 'account', ['ALL'], false);

        $this->run_(fn () => $this->exec($session, $light, 'USD', '10'), 'p_currency');   // monedhë jo e lejuar
        $this->run_(fn () => $this->exec($session, $off, 'ALL', '10'), 'p_service');      // shërbim jo aktiv
        $this->run_(fn () => $this->exec($session, 999999, 'ALL', '10'), 'p_service');    // s'ekziston
        $this->run_(fn () => $this->exec($session, $light, 'ALL', '0'), 'p_amount');
        $this->run_(fn () => $this->exec($session, $light, 'ALL', 'abc'), 'p_amount');
        $this->run_(fn () => $this->exec($session, $light, 'ALL', '10', null, 424242), 'p_amount'); // jo pronari i seancës
    }

    public function test_inactive_partner_blocks_its_services(): void
    {
        [, $session] = $this->readyTill();
        $partner = $this->makePartner();
        $svc = $this->makeService($partner, 'Drita', 'in', 'account', ['ALL']);
        DB::table('fx_partners')->where('id', $partner)->update(['is_active' => false]);

        $this->run_(fn () => $this->exec($session, $svc, 'ALL', '10'), 'p_service');
    }

    public function test_session_must_be_open(): void
    {
        [, $session] = $this->readyTill();
        $partner = $this->makePartner();
        $svc = $this->makeService($partner, 'Drita', 'in', 'account', ['ALL']);
        app(FxTillService::class)->close($session, ['ALL' => '1000000', 'EUR' => '0'], null, 9001);

        $this->run_(fn () => $this->exec($session, $svc, 'ALL', '10'), 'p_amount');
    }

    public function test_amount_is_rounded_to_two_decimals(): void
    {
        [, $session] = $this->readyTill();
        $partner = $this->makePartner();
        $svc = $this->makeService($partner, 'Drita', 'in', 'account', ['ALL']);

        $this->assertSame('10.01', (string) $this->exec($session, $svc, 'ALL', '10.005')->amount);
    }

    public function test_void_reverses_cash_and_partner_ledger(): void
    {
        [$till, $session] = $this->readyTill();
        $partner = $this->makePartner();
        $light = $this->makeService($partner, 'Energji', 'in', 'prefunded');
        $this->prefund($partner, '20000');
        $op = $this->exec($session, $light, 'ALL', '5000');

        $reversal = $this->ops()->void($op, 9100, 'pagesë e gabuar');

        $this->assertTrue($reversal->isReversal());
        $this->assertSame($op->id, (int) $reversal->voids_operation_id);
        $this->assertSame('1000000.00', $this->balances($till)['ALL']);
        $this->assertSame('20000.00', $this->ops()->balances($partner)->get('ALL')['prepaid']);
    }

    public function test_void_rules(): void
    {
        [$till, $session] = $this->readyTill();
        $partner = $this->makePartner();
        $svc = $this->makeService($partner, 'Drita', 'in', 'account', ['ALL']);
        $op = $this->exec($session, $svc, 'ALL', '1000');

        $this->run_(fn () => $this->ops()->void($op, 9100, 'abc'), 'reason');                  // arsye < 5 shkronja
        $reversal = $this->ops()->void($op, 9100, 'arsye e plotë');
        $this->run_(fn () => $this->ops()->void($op, 9100, 'arsye e plotë'), 'reason');        // dy herë
        $this->run_(fn () => $this->ops()->void($reversal, 9100, 'arsye e plotë'), 'reason');  // anulim i anulimit
    }

    public function test_void_of_an_in_operation_needs_the_cash_to_still_be_in_the_till(): void
    {
        [$till, $session] = $this->readyTill('1000000', '0');
        $partner = $this->makePartner();
        $svc = $this->makeService($partner, 'Drita', 'in', 'account', ['ALL']);
        $op = $this->exec($session, $svc, 'ALL', '1000');

        // arka derdh gjithë Lekun (supervizori) para anulimit
        app(FxTillService::class)->movement($session, 'cash_out', 'ALL', '1001000', 'derdhje', 9100);

        $this->run_(fn () => $this->ops()->void($op, 9100, 'pagesë e gabuar'), 'reason');
    }

    public function test_void_is_refused_on_another_day_or_after_closing(): void
    {
        [, $session] = $this->readyTill();
        $partner = $this->makePartner();
        $svc = $this->makeService($partner, 'Drita', 'out', 'account', ['ALL']);
        $a = $this->exec($session, $svc, 'ALL', '100');
        $b = $this->exec($session, $svc, 'ALL', '200');

        // ditë tjetër, seanca ende e hapur
        Carbon::setTestNow(now()->addDay());
        $this->run_(fn () => $this->ops()->void($b, 9100, 'ditë tjetër'), 'reason');
        Carbon::setTestNow();

        // e njëjta ditë, por seanca e mbyllur
        app(FxTillService::class)->close($session, ['ALL' => '999700', 'EUR' => '0'], null, 9001);
        $this->run_(fn () => $this->ops()->void($a, 9100, 'pas mbylljes'), 'reason');
    }

    public function test_partner_sequence_is_separate_from_exchange_sequence_and_chain_verifies(): void
    {
        [, $session, $eur, $office] = $this->readyTill();
        $partner = $this->makePartner();
        $svc = $this->makeService($partner, 'Drita', 'in', 'account', ['ALL']);

        $fx = app(FxCounterService::class)->execute($session, 9001, ['direction' => 'buy', 'currency_id' => $eur->id, 'foreign_amount' => '10']);
        $p1 = $this->exec($session, $svc, 'ALL', '100');
        $p2 = $this->exec($session, $svc, 'ALL', '200');

        $this->assertSame(1, (int) $fx->seq);
        $this->assertSame([1, 2], [(int) $p1->seq, (int) $p2->seq]);
        $this->assertSame($p1->hash, $p2->prev_hash);
        $this->assertSame([], app(FxLedgerService::class)->verifyPartner($office, now()->year));
        $this->assertSame([], app(FxLedgerService::class)->verify($office, now()->year));
    }

    public function test_tampering_with_a_partner_operation_is_detected(): void
    {
        [, $session, , $office] = $this->readyTill();
        $partner = $this->makePartner();
        $svc = $this->makeService($partner, 'Drita', 'in', 'account', ['ALL']);
        $op = $this->exec($session, $svc, 'ALL', '100');
        $this->exec($session, $svc, 'ALL', '200');

        DB::table('fx_partner_operations')->where('id', $op->id)->update(['amount' => '1.00']);

        $errors = app(FxLedgerService::class)->verifyPartner($office, now()->year);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Hash i pavlefshëm', implode(' ', $errors));
    }

    public function test_low_prepaid_alert_lists_partners_below_threshold_only(): void
    {
        [, $session] = $this->readyTill();
        $partner = $this->makePartner('LOW', 50000);
        $this->makeService($partner, 'Drita', 'in', 'prefunded');

        $this->prefund($partner, '20000');
        $low = $this->ops()->lowPrepaid()->first(fn ($r) => $r['partner']->id === $partner);
        $this->assertNotNull($low, 'Gjendja 20 000 < pragu 50 000');
        $this->assertSame('20000.00', $low['balance']);

        $this->prefund($partner, '40000'); // 60 000 total
        $this->assertNull($this->ops()->lowPrepaid()->first(fn ($r) => $r['partner']->id === $partner));
    }
}
