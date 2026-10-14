<?php

namespace Botble\EmironFx\Services;

use Botble\EmironFx\Models\FxHoliday;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Ditët e punës për afatet (raporti i BSH-së "ditën pasardhëse të punës", kujtesat, importi).
 *
 * Festat e fiksuara zyrtare dhe Pashkët (katolike e ortodokse) llogariten vetë;
 * Bajrami i Madh dhe Kurban Bajrami (kalendar hënor) dhe çdo ditë pushimi e shpallur
 * nga qeveria shtohen te fx_holidays nga paneli. Kur festa bie të dielën,
 * e hëna pasardhëse shënohet pushim (sipas praktikës shqiptare).
 */
class FxCalendar
{
    private const FIXED = [
        '01-01' => 'Viti i Ri',
        '01-02' => 'Viti i Ri',
        '03-14' => 'Dita e Verës',
        '03-22' => 'Dita e Nevruzit',
        '05-01' => 'Dita Ndërkombëtare e Punëtorëve',
        '11-28' => 'Dita e Pavarësisë',
        '11-29' => 'Dita e Çlirimit',
        '12-08' => 'Dita Kombëtare e Rinisë',
        '12-25' => 'Krishtlindjet',
    ];

    /** @var array<int, array<string,string>> */
    private array $cache = [];

    /** @return array<string,string> 'Y-m-d' => emri */
    public function holidays(int $year): array
    {
        if (isset($this->cache[$year])) {
            return $this->cache[$year];
        }

        $days = [];

        foreach (self::FIXED as $md => $name) {
            $days["{$year}-{$md}"] = $name;
        }

        $catholic = $this->catholicEaster($year);
        $orthodox = $this->orthodoxEaster($year);
        $days[$catholic->toDateString()] = 'Pashkët Katolike';
        $days[$catholic->copy()->addDay()->toDateString()] = 'Pashkët Katolike';
        $days[$orthodox->toDateString()] = 'Pashkët Ortodokse';
        $days[$orthodox->copy()->addDay()->toDateString()] = 'Pashkët Ortodokse';

        foreach (FxHoliday::query()->whereYear('holiday_date', $year)->get() as $h) {
            $days[$h->holiday_date->toDateString()] = $h->name;
        }

        // Festë e dielë → pushim të hënën.
        foreach ($days as $date => $name) {
            $d = Carbon::parse($date);

            if ($d->isSunday()) {
                $monday = $d->copy()->addDay()->toDateString();
                $days[$monday] ??= $name . ' (pushim)';
            }
        }

        ksort($days);

        return $this->cache[$year] = $days;
    }

    public function isHoliday(CarbonInterface $date): ?string
    {
        return $this->holidays((int) $date->format('Y'))[$date->toDateString()] ?? null;
    }

    public function isBusinessDay(CarbonInterface $date): bool
    {
        return ! $date->isWeekend() && $this->isHoliday($date) === null;
    }

    public function nextBusinessDay(CarbonInterface $date): CarbonInterface
    {
        $next = $date->copy()->addDay()->startOfDay();

        while (! $this->isBusinessDay($next)) {
            $next->addDay();
        }

        return $next;
    }

    public function previousBusinessDay(CarbonInterface $date): CarbonInterface
    {
        $prev = $date->copy()->subDay()->startOfDay();

        while (! $this->isBusinessDay($prev)) {
            $prev->subDay();
        }

        return $prev;
    }

    private function catholicEaster(int $year): Carbon
    {
        // Algoritmi anonim gregorian (Meeus/Jones/Butcher).
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::create($year, $month, $day, 0, 0, 0, config('app.timezone'));
    }

    private function orthodoxEaster(int $year): Carbon
    {
        // Algoritmi i Meeus për kalendarin julian, + diferenca julian→gregorian (13 ditë, 1900–2099).
        $a = $year % 4;
        $b = $year % 7;
        $c = $year % 19;
        $d = (19 * $c + 15) % 30;
        $e = (2 * $a + 4 * $b - $d + 34) % 7;
        $month = intdiv($d + $e + 114, 31);
        $day = (($d + $e + 114) % 31) + 1;

        return Carbon::create($year, $month, $day, 0, 0, 0, config('app.timezone'))->addDays(13);
    }
}
