<?php

namespace Botble\EmironFx\Tests\Feature;

use Botble\EmironFx\Services\FxCalendar;
use Botble\EmironFx\Tests\Support\FxTestCase;
use Illuminate\Support\Carbon;

/** Ditët e punës: festat e fiksuara, Pashkët, festa e dielë → e hëna, ditët e punës para/pas. */
class CalendarTest extends FxTestCase
{
    private function cal(): FxCalendar
    {
        return new FxCalendar();
    }

    private function d(string $date): Carbon
    {
        return Carbon::parse($date, config('app.timezone'));
    }

    public function test_fixed_holidays_are_not_business_days(): void
    {
        $cal = $this->cal();

        $this->assertNotNull($cal->isHoliday($this->d('2026-01-01')));
        $this->assertNotNull($cal->isHoliday($this->d('2026-05-01')));
        $this->assertNotNull($cal->isHoliday($this->d('2026-11-28')));
        $this->assertNotNull($cal->isHoliday($this->d('2026-12-25')));
        $this->assertFalse($cal->isBusinessDay($this->d('2026-05-01')), '1 maji 2026 është e premte, festë');
        $this->assertTrue($cal->isBusinessDay($this->d('2026-12-24')), '24 dhjetori 2026 është e enjte, ditë pune');
    }

    public function test_easter_2026_catholic_and_orthodox(): void
    {
        $days = $this->cal()->holidays(2026);

        $this->assertSame('Pashkët Katolike', $days['2026-04-05']);
        $this->assertSame('Pashkët Katolike', $days['2026-04-06']);
        $this->assertSame('Pashkët Ortodokse', $days['2026-04-12']);
        $this->assertSame('Pashkët Ortodokse', $days['2026-04-13']);
    }

    public function test_a_holiday_on_sunday_moves_the_rest_day_to_monday(): void
    {
        // 29.11.2026 është e diel (Dita e Çlirimit) → e hëna 30.11 pushim
        $cal = $this->cal();

        $this->assertTrue($this->d('2026-11-29')->isSunday());
        $this->assertSame('Dita e Çlirimit (pushim)', $cal->holidays(2026)['2026-11-30']);
        $this->assertFalse($cal->isBusinessDay($this->d('2026-11-30')));
    }

    public function test_weekends_are_not_business_days(): void
    {
        $cal = $this->cal();

        $this->assertFalse($cal->isBusinessDay($this->d('2026-10-10'))); // e shtunë
        $this->assertFalse($cal->isBusinessDay($this->d('2026-10-11'))); // e diel
        $this->assertTrue($cal->isBusinessDay($this->d('2026-10-12')));  // e hënë
    }

    public function test_next_business_day_skips_weekend_and_sunday_compensation(): void
    {
        $cal = $this->cal();

        $this->assertSame('2026-10-12', $cal->nextBusinessDay($this->d('2026-10-09'))->toDateString()); // e premte → e hënë
        // e premte 27.11 → (28 e shtunë festë, 29 e diel festë, 30 pushim) → e marta 01.12
        $this->assertSame('2026-12-01', $cal->nextBusinessDay($this->d('2026-11-27'))->toDateString());
    }

    public function test_previous_business_day_skips_new_year(): void
    {
        $cal = $this->cal();

        // e hëna 05.01.2026: 04 e diel, 03 e shtunë, 02 dhe 01 festa → e mërkura 31.12.2025
        $this->assertSame('2025-12-31', $cal->previousBusinessDay($this->d('2026-01-05'))->toDateString());
    }
}
