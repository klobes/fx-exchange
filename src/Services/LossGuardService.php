<?php

namespace Botble\EmironFx\Services;

use Botble\EmironFx\Models\FxAccessLog;
use Botble\EmironFx\Models\FxCurrency;
use Botble\EmironFx\Models\FxTillMovement;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Mbrojtja nga humbja në shitje (0.8.4): kur zyra SHET një valutë me kurs më të ulët se kostoja mesatare e stokut
 * të saj (blerë më shtrenjtë), shitja është me humbje. Mënyra (cilësim te paneli Burimet, ose FX_LOSS_GUARD_MODE):
 *   off   — pa kontroll;
 *   warn  — lejohet, shënohet me flamurin `below_cost` dhe paralajmërohet në ekran;
 *   block — bllokohet; kalon vetëm me lejen `fx.counter.below_cost` (supervizor) + arsye ≥ 5 shkronja, e regjistruar.
 *
 * Kostoja: mesatarja e ponderuar e stokut (FxPnlService, Lek për njësinë e zyrës); kur FIFO të hyjë në fuqi
 * (0.8.5) ndërrohet vetëm costPerUnit(). Nuk ka kosto kur s'ka stok → pa kontroll.
 */
class LossGuardService
{
    public const MODES = ['off', 'warn', 'block'];

    public function __construct(
        private readonly FxPnlService $pnl,
        private readonly MarketRateService $settings,
    ) {
    }

    public function mode(): string
    {
        $mode = (string) $this->settings->setting('loss_guard_mode', (string) config('plugins.emiron-fx.general.loss_guard_mode', 'block'));

        return in_array($mode, self::MODES, true) ? $mode : 'block';
    }

    /**
     * Kostoja mesatare e stokut për njësi zyre (Lek) ose null kur s'ka stok. E ruajtur për pak minuta,
     * me çelës = ID e lëvizjes së fundit të arkës (çdo lëvizje e re e pavlefëson vetvetiu).
     *
     * @return array<string, string> kodi => kostoja
     */
    public function costs(): array
    {
        try {
            $maxId = (int) FxTillMovement::query()->max('id');

            return Cache::remember('fx:costs:' . $maxId, 300, function (): array {
                $out = [];

                foreach ($this->pnl->compute(now(), now())['rows'] as $code => $row) {
                    if (FxMoney::cmp($row['qty'], '0') > 0 && FxMoney::cmp($row['avg'], '0') > 0) {
                        $out[$code] = (string) $row['avg'];
                    }
                }

                return $out;
            });
        } catch (Throwable) {
            return [];
        }
    }

    public function costPerUnit(string $code): ?string
    {
        return $this->costs()[$code] ?? null;
    }

    /**
     * @return array{below: bool, cost: ?string, rate: string, loss: string, code: string}
     */
    public function evaluate(FxCurrency $currency, string $sellRate, string $foreign): array
    {
        $cost = $this->costPerUnit($currency->code);
        $below = $cost !== null && FxMoney::cmp($sellRate, $cost) < 0;
        $loss = '0';

        if ($below) {
            $perUnit = FxMoney::sub($cost, $sellRate);
            $loss = FxMoney::round(FxMoney::div(FxMoney::mul($perUnit, $foreign), (string) max(1, (int) $currency->unit)), 0);
        }

        return ['below' => $below, 'cost' => $cost, 'rate' => $sellRate, 'loss' => $loss, 'code' => $currency->code];
    }

    /**
     * Zbaton politikën. Kthen flamurin `below_cost` kur shitja kalon (me humbje), null kur s'ka humbje/kontroll;
     * hedh ValidationException kur mënyra është "block" pa leje + arsye.
     *
     * @param  array{below: bool, cost: ?string, rate: string, loss: string, code: string}  $eval
     */
    public function enforce(array $eval, bool $canOverride, bool $overrideRequested, ?string $reason): ?string
    {
        $mode = $this->mode();

        if ($mode === 'off' || ! $eval['below']) {
            return null;
        }

        if ($mode === 'warn') {
            return 'below_cost';
        }

        if ($canOverride && $overrideRequested) {
            if (mb_strlen(trim((string) $reason)) < 5) {
                throw ValidationException::withMessages(['below_cost_reason' => trans('plugins/emiron-fx::fx.counter.errors.below_cost_reason')]);
            }

            return 'below_cost';
        }

        throw ValidationException::withMessages(['foreign_amount' => trans('plugins/emiron-fx::fx.counter.errors.below_cost', [
            'code' => $eval['code'],
            'cost' => number_format((float) $eval['cost'], 4, '.', ''),
            'rate' => number_format((float) $eval['rate'], 4, '.', ''),
            'loss' => number_format((float) $eval['loss'], 0, '.', ' '),
        ])]);
    }

    /** Gjurma e shitjes nën kosto (audit) + njoftim me email (një herë për veprim). */
    public function record(array $eval, string $number, int $transactionId, ?string $reason): void
    {
        $note = sprintf(
            '%s %s: kosto %s, kurs %s, humbje ≈ %s Lek%s',
            $number,
            $eval['code'],
            number_format((float) $eval['cost'], 4, '.', ''),
            number_format((float) $eval['rate'], 4, '.', ''),
            number_format((float) $eval['loss'], 0, '.', ' '),
            $reason ? '; arsye: ' . mb_substr(trim($reason), 0, 200) : ''
        );

        try {
            FxAccessLog::record('below_cost_sale', 'transaction', $transactionId, $note);
            app(FxAlertService::class)->belowCost($note, $number);
        } catch (Throwable) {
            // gjurma/njoftimi s'duhet ta prishë veprimin e kryer
        }
    }
}
