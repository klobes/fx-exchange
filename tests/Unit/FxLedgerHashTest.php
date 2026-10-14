<?php

namespace Botble\EmironFx\Tests\Unit;

use Botble\EmironFx\Services\FxLedgerService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** Zinxhiri hash i regjistrit (pjesa e pastër; verifikimi me DB është te Feature/LedgerChainTest). */
class FxLedgerHashTest extends TestCase
{
    private function row(array $override = []): array
    {
        return $override + [
            'office_id' => 1, 'year' => 2026, 'seq' => 7, 'number' => 'Z1-2026-000007', 'till_id' => 1,
            'till_session_id' => 3, 'user_id' => 9001, 'business_date' => '2026-10-08',
            'occurred_at' => '2026-10-08 10:15:00', 'direction' => 'buy', 'currency_code' => 'EUR', 'unit' => 1,
            'foreign_amount' => '100.00', 'rate' => '100.000000', 'lek_amount' => '10000.00',
            'official_rate' => '101.000000', 'eur_equivalent' => '99.01', 'client_category' => 'Individë',
            'payment_method' => 'cash', 'voids_transaction_id' => null,
        ];
    }

    public function test_hash_is_deterministic_sha256(): void
    {
        $ledger = new FxLedgerService();
        $h1 = $ledger->hash($this->row(), null);

        $this->assertSame($h1, $ledger->hash($this->row(), null));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $h1);
    }

    public function test_every_canonical_field_changes_the_hash(): void
    {
        $ledger = new FxLedgerService();
        $base = $ledger->hash($this->row(), null);

        $changes = [
            'office_id' => 2, 'year' => 2027, 'seq' => 8, 'number' => 'Z1-2026-000008', 'till_id' => 2,
            'till_session_id' => 4, 'user_id' => 9002, 'business_date' => '2026-10-09',
            'occurred_at' => '2026-10-08 10:15:01', 'direction' => 'sell', 'currency_code' => 'USD', 'unit' => 100,
            'foreign_amount' => '100.01', 'rate' => '100.000001', 'lek_amount' => '10000.01',
            'official_rate' => '101.000001', 'eur_equivalent' => '99.02', 'client_category' => 'Biznes',
            'payment_method' => 'card', 'voids_transaction_id' => 5,
        ];

        foreach ($changes as $field => $value) {
            $this->assertNotSame($base, $ledger->hash($this->row([$field => $value]), null), "Fusha {$field} duhet të ndikojë në hash");
        }
    }

    public function test_previous_hash_chains_the_rows(): void
    {
        $ledger = new FxLedgerService();

        $this->assertNotSame($ledger->hash($this->row(), null), $ledger->hash($this->row(), str_repeat('a', 64)));
        $this->assertNotSame($ledger->hash($this->row(), str_repeat('a', 64)), $ledger->hash($this->row(), str_repeat('b', 64)));
        // prev_hash null dhe '' janë i njëjti fillim zinxhiri
        $this->assertSame($ledger->hash($this->row(), null), $ledger->hash($this->row(), ''));
    }

    public function test_customer_id_is_part_of_hash_only_when_present(): void
    {
        $ledger = new FxLedgerService();
        $without = $ledger->hash($this->row(), null);

        // Veprimet e 0.4.0 (pa klient) mbeten të verifikueshme: customer_id bosh/null s'e ndryshon hash-in.
        $this->assertSame($without, $ledger->hash($this->row(['customer_id' => null]), null));
        $this->assertSame($without, $ledger->hash($this->row(['customer_id' => 0]), null));

        $with = $ledger->hash($this->row(['customer_id' => 12]), null);
        $this->assertNotSame($without, $with);
        $this->assertNotSame($with, $ledger->hash($this->row(['customer_id' => 13]), null));
    }

    public function test_cross_fields_are_part_of_hash_only_when_present(): void
    {
        $ledger = new FxLedgerService();
        $plain = $ledger->hash($this->row(), null);

        // veprimet e zakonshme (pa leg / pa kusur) mbeten të verifikueshme: null/bosh s'ndryshon hash-in
        $this->assertSame($plain, $ledger->hash($this->row(['leg' => null, 'pair_transaction_id' => null, 'change_amount' => null, 'change_currency' => null]), null));
        $this->assertSame($plain, $ledger->hash($this->row(['change_amount' => '', 'change_currency' => '']), null));

        // kur ekzistojnë, secila fushë e ndryshon hash-in
        foreach (['leg' => 1, 'pair_transaction_id' => 5, 'change_amount' => '1.00', 'change_currency' => 'ALL'] as $field => $value) {
            $this->assertNotSame($plain, $ledger->hash($this->row([$field => $value]), null), "Fusha {$field} duhet të hyjë në hash kur ekziston");
        }

        $a = $ledger->hash($this->row(['leg' => 2, 'pair_transaction_id' => 5, 'change_amount' => '1.00', 'change_currency' => 'ALL']), null);
        $this->assertNotSame($a, $ledger->hash($this->row(['leg' => 2, 'pair_transaction_id' => 6, 'change_amount' => '1.00', 'change_currency' => 'ALL']), null));
        $this->assertNotSame($a, $ledger->hash($this->row(['leg' => 2, 'pair_transaction_id' => 5, 'change_amount' => '1.01', 'change_currency' => 'ALL']), null));
        // string/int janë ekuivalentë (DB kthen int, regjistrimi dërgon int)
        $this->assertSame($a, $ledger->hash($this->row(['leg' => '2', 'pair_transaction_id' => '5', 'change_amount' => '1.00', 'change_currency' => 'ALL']), null));
    }

    public function test_datetime_objects_hash_like_their_formatted_strings(): void
    {
        $ledger = new FxLedgerService();

        $this->assertSame(
            $ledger->hash($this->row(), null),
            $ledger->hash($this->row(['occurred_at' => new DateTimeImmutable('2026-10-08 10:15:00')]), null)
        );
    }

    /**
     * DOKUMENTON sjelljen AKTUALE (0.7.x): flags, rate_id, currency_id, cdd_reference dhe void_reason
     * NUK janë pjesë e hash-it. Kur të dalë hash-i i zgjeruar (plani 0.8.x, me versionim), ky test
     * duhet kthyer: ndryshimi i tyre duhet ta ndryshojë hash-in.
     */
    public function test_fields_outside_the_canonical_list_do_not_affect_hash_current_behaviour(): void
    {
        $ledger = new FxLedgerService();
        $base = $ledger->hash($this->row(), null);

        foreach (['flags' => ['aif_cash'], 'rate_id' => 99, 'currency_id' => 9, 'cdd_reference' => 'X', 'void_reason' => 'y'] as $field => $value) {
            $this->assertSame($base, $ledger->hash($this->row([$field => $value]), null), "Sjellje aktuale: {$field} s'hyn në hash");
        }
    }
}
