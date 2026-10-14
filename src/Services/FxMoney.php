<?php

namespace Botble\EmironFx\Services;

/**
 * Aritmetika e parave me bcmath (string), pa float.
 * Rrumbullakimi: gjysma larg zeros (0.5 → 1, −0.5 → −1), si në arkë.
 */
final class FxMoney
{
    public const SCALE = 10;

    public static function round(string $value, int $decimals): string
    {
        $value = self::normalize($value);
        $offset = '0.' . str_repeat('0', $decimals) . '5';
        $rounded = str_starts_with($value, '-')
            ? bcsub($value, $offset, $decimals)
            : bcadd($value, $offset, $decimals);

        return self::clean($rounded, $decimals);
    }

    /** Poshtë (drejt zeros) në $decimals shifra — vetëm për shuma jo-negative. */
    public static function floor(string $value, int $decimals): string
    {
        return self::clean(bcadd(self::normalize($value), '0', $decimals), $decimals);
    }

    /** Lart në $decimals shifra (p.sh. 12.341 → 12.35) — vetëm për shuma jo-negative. */
    public static function ceil(string $value, int $decimals): string
    {
        $value = self::normalize($value);
        $floor = bcadd($value, '0', $decimals);

        if (bccomp($value, $floor, self::SCALE) > 0) {
            $step = $decimals === 0 ? '1' : '0.' . str_repeat('0', $decimals - 1) . '1';
            $floor = bcadd($floor, $step, $decimals);
        }

        return self::clean($floor, $decimals);
    }

    /** Lek për një shumë valute: shuma × kursi ÷ njësia. */
    public static function lekFor(string $foreign, string $rate, int $unit, int $lekDecimals): string
    {
        $raw = bcdiv(bcmul($foreign, $rate, self::SCALE), (string) max(1, $unit), self::SCALE);

        return self::round($raw, $lekDecimals);
    }

    public static function add(string $a, string $b): string
    {
        return bcadd($a, $b, self::SCALE);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub($a, $b, self::SCALE);
    }

    public static function mul(string $a, string $b): string
    {
        return bcmul($a, $b, self::SCALE);
    }

    public static function div(string $a, string $b): string
    {
        return bccomp($b, '0', self::SCALE) === 0 ? '0' : bcdiv($a, $b, self::SCALE);
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE);
    }

    public static function neg(string $a): string
    {
        return bcmul($a, '-1', self::SCALE);
    }

    public static function isZero(string $a): bool
    {
        return bccomp($a, '0', self::SCALE) === 0;
    }

    /** "1.2e3", "1 000,50", null → formë e pranueshme për bcmath. */
    public static function normalize(mixed $value): string
    {
        $value = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim((string) $value));

        if ($value === '' || ! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            return '0';
        }

        return $value;
    }

    public static function clean(string $value, int $decimals = 2): string
    {
        $value = bcadd($value, '0', $decimals);

        return $value === '-0' || preg_match('/^-0\.0+$/', $value) ? ltrim($value, '-') : $value;
    }
}
