<?php

namespace Botble\EmironFx\Tests\Unit;

use Botble\EmironFx\Services\FxMoney;
use PHPUnit\Framework\TestCase;

/** Aritmetika e parave (bcmath): rrumbullakimi, Lek për valutë, normalizimi. Pa DB, pa aplikacion. */
class FxMoneyTest extends TestCase
{
    public function test_round_is_half_away_from_zero(): void
    {
        $this->assertSame('3', FxMoney::round('2.5', 0));
        $this->assertSame('-3', FxMoney::round('-2.5', 0));
        $this->assertSame('2', FxMoney::round('2.4', 0));
        $this->assertSame('1', FxMoney::round('0.5', 0));
        $this->assertSame('1.01', FxMoney::round('1.005', 2));
        $this->assertSame('1.00', FxMoney::round('1.004', 2));
        $this->assertSame('-1.01', FxMoney::round('-1.005', 2));
    }

    public function test_round_never_returns_negative_zero(): void
    {
        $this->assertSame('0', FxMoney::round('-0.4', 0));
        $this->assertSame('0.00', FxMoney::round('-0.004', 2));
    }

    public function test_floor_truncates_towards_zero(): void
    {
        $this->assertSame('108.69', FxMoney::floor('108.6956521739', 2));
        $this->assertSame('9', FxMoney::floor('9.9', 0));
        $this->assertSame('100.00', FxMoney::floor('100', 2));
        $this->assertSame('0.00', FxMoney::floor('0.004', 2));
    }

    public function test_ceil_rounds_up_only_when_there_is_a_remainder(): void
    {
        $this->assertSame('99.99', FxMoney::ceil('99.99', 2));
        $this->assertSame('100.00', FxMoney::ceil('99.9901', 2));
        $this->assertSame('94.85', FxMoney::ceil('94.8453608247', 2));
        $this->assertSame('92.01', FxMoney::ceil('92.0000001', 2));
        $this->assertSame('10', FxMoney::ceil('9.1', 0));
        $this->assertSame('9', FxMoney::ceil('9', 0));
        $this->assertSame('0.01', FxMoney::ceil('0.0000001', 2));
    }

    public function test_lek_for_uses_rate_and_unit(): void
    {
        // 100 EUR @ 101.5 Lek
        $this->assertSame('10150', FxMoney::lekFor('100.00', '101.5', 1, 0));
        // kursi me 6 dhjetore, rrumbullakim në Lek të plota
        $this->assertSame('4956', FxMoney::lekFor('50', '99.123456', 1, 0));
        // monedhë me njësi 100 (p.sh. JPY: Lek për 100 JPY)
        $this->assertSame('1630', FxMoney::lekFor('2500', '65.2', 100, 0));
        $this->assertSame('8', FxMoney::lekFor('1000', '0.8', 100, 0));
    }

    public function test_lek_for_with_two_decimals(): void
    {
        // 12.34 × 99.81 = 1231.6554
        $this->assertSame('1231.66', FxMoney::lekFor('12.34', '99.81', 1, 2));
        $this->assertSame('0.01', FxMoney::lekFor('0.0001', '100', 1, 2));
    }

    public function test_lek_for_treats_unit_below_one_as_one(): void
    {
        $this->assertSame('200', FxMoney::lekFor('2', '100', 0, 0));
    }

    public function test_normalize_accepts_common_formats_and_rejects_garbage(): void
    {
        $this->assertSame('1000.50', FxMoney::normalize('1 000,50'));
        $this->assertSame('1000.50', FxMoney::normalize("1\u{00A0}000.50"));
        $this->assertSame('-5', FxMoney::normalize(' -5 '));
        $this->assertSame('0', FxMoney::normalize(''));
        $this->assertSame('0', FxMoney::normalize(null));
        $this->assertSame('0', FxMoney::normalize('abc'));
        $this->assertSame('0', FxMoney::normalize('12abc'));
    }

    public function test_add_sub_mul_div_keep_precision_without_float_errors(): void
    {
        $this->assertSame(0, FxMoney::cmp(FxMoney::add('0.1', '0.2'), '0.3'));
        $this->assertSame(0, FxMoney::cmp(FxMoney::sub('1', '0.9'), '0.1'));
        $this->assertSame(0, FxMoney::cmp(FxMoney::mul('19.99', '3'), '59.97'));
        $this->assertSame(0, FxMoney::cmp(FxMoney::div('1', '3'), '0.3333333333'));
    }

    public function test_div_by_zero_returns_zero(): void
    {
        $this->assertSame('0', FxMoney::div('10', '0'));
        $this->assertSame('0', FxMoney::div('10', '0.0000'));
    }

    public function test_cmp_neg_and_is_zero(): void
    {
        $this->assertSame(1, FxMoney::cmp('2', '1'));
        $this->assertSame(-1, FxMoney::cmp('1', '2'));
        $this->assertSame(0, FxMoney::cmp('1.0', '1.00'));
        $this->assertSame(0, FxMoney::cmp(FxMoney::neg('5'), '-5'));
        $this->assertTrue(FxMoney::isZero('0.0000'));
        $this->assertFalse(FxMoney::isZero('0.0001'));
    }

    public function test_clean_formats_to_requested_decimals_and_strips_negative_zero(): void
    {
        $this->assertSame('990000.00', FxMoney::clean('990000'));
        $this->assertSame('12.3', FxMoney::clean('12.30', 1));
        $this->assertSame('0.00', FxMoney::clean('-0.001', 2));
    }
}
