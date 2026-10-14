<?php

if (! function_exists('fx_can')) {
    /**
     * Kontrolli i lejeve të Emiron FX — fail-closed.
     * Evaluatori i Botble-s (RULE_112 lejon evaluatorin e pronarit për module
     * të jashtme). Pa përdorues ose pa leje → false.
     */
    function fx_can(string $flag): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'isSuperUser') && $user->isSuperUser()) {
            return true;
        }

        return method_exists($user, 'hasPermission') && $user->hasPermission($flag);
    }
}

if (! function_exists('fx_trend')) {
    /**
     * Shigjeta e ndryshimit: ▲ u rrit, ▼ u ul, asgjë kur s'ka ndryshim/histori.
     * Teksti i aksesueshëm tregon vlerën e mëparshme.
     */
    function fx_trend(int $trend, ?string $previous): string
    {
        if ($trend === 0 || $previous === null) {
            return '';
        }

        $label = trans($trend > 0 ? 'plugins/emiron-fx::fx.public.trend_up' : 'plugins/emiron-fx::fx.public.trend_down', ['from' => fx_rate($previous)]);

        return sprintf(
            '<span class="fx-trend %s" title="%s"><span aria-hidden="true">%s</span><span class="fx-sr">%s</span></span>',
            $trend > 0 ? 'fx-trend--up' : 'fx-trend--down',
            e($label),
            $trend > 0 ? '▲' : '▼',
            e($label)
        );
    }
}

if (! function_exists('fx_rate')) {
    /** Kurs për shfaqje: 98,1500 → "98.15" (heq zerot në fund, mban min. 2 shifra). */
    function fx_rate(string|float|null $rate, ?int $decimals = null): string
    {
        if ($rate === null || $rate === '') {
            return '—';
        }

        $decimals ??= (int) config('plugins.emiron-fx.general.rate_decimals', 4);
        $formatted = number_format((float) $rate, $decimals, '.', ' ');

        if (str_contains($formatted, '.')) {
            [$int, $frac] = explode('.', $formatted);
            $frac = rtrim($frac, '0');
            $frac = str_pad($frac, 2, '0');
            $formatted = $int . '.' . $frac;
        }

        return $formatted;
    }
}
