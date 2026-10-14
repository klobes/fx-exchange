<?php

namespace Botble\EmironFx\Tests\Feature;

use Botble\EmironFx\Models\FxAlert;
use Botble\EmironFx\Models\FxCurrency;
use Botble\EmironFx\Models\FxRate;
use Botble\EmironFx\Models\FxReferenceRate;
use Botble\EmironFx\Services\MarketRateService;
use Botble\EmironFx\Services\SourceRegistry;
use Botble\EmironFx\Services\SourceSyncService;
use Botble\EmironFx\Tests\Support\FxTestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Kursi i rekomanduar nga tregu (0.8.2): mediana e burimeve "në mesatare", hedhja e outlier-ave dhe e të vjetërve,
 * mënyrat e marzhit (last | percent | lek), lëvizja (drift) dhe njoftimi një herë.
 */
class MarketRateTest extends FxTestCase
{
    private FxCurrency $eur;

    protected function setUp(): void
    {
        parent::setUp();

        config(['plugins.emiron-fx.general.source_retry_sleep_ms' => 0]);
        $this->eur = $this->currency('EUR');
        $this->setOfficial($this->eur, '91.65'); // BSH
    }

    private function market(): MarketRateService
    {
        return app(MarketRateService::class);
    }

    /** Aktivizon burimin JSON dhe e (ç)shënon "në mesatare". */
    private function useSource(string $code, bool $enabled = true, bool $inAverage = true): void
    {
        DB::table('fx_rate_sources')->where('code', $code)->update(['enabled' => $enabled]);
        DB::table('fx_source_settings')->updateOrInsert(['source_code' => $code], ['use_in_average' => $inAverage, 'created_at' => now(), 'updated_at' => now()]);
        app(SourceRegistry::class)->refresh();
    }

    private function ref(string $source, string $rate, ?Carbon $date = null): void
    {
        FxReferenceRate::query()->create([
            'currency_id' => $this->eur->id, 'source' => $source, 'rate_date' => ($date ?? now())->toDateString(),
            'rate' => $rate, 'basis' => 'test',
        ]);
    }

    private function published(string $buy, string $sell, ?string $buyDev = null, ?string $sellDev = null): FxRate
    {
        return FxRate::query()->create([
            'currency_id' => $this->eur->id, 'buy_rate' => $buy, 'sell_rate' => $sell,
            'buy_deviation_pct' => $buyDev, 'sell_deviation_pct' => $sellDev,
            'effective_at' => now(), 'batch_uuid' => (string) Str::uuid(),
        ]);
    }

    public function test_only_wise_is_in_the_average_by_default(): void
    {
        $this->useSource('wise', true, true);
        $this->useSource('floatrates', true, false);
        $this->ref('wise', '91.675');
        $this->ref('floatrates', '91.50');

        $c = $this->market()->consensus()->get($this->eur->id);

        $this->assertEqualsWithDelta(91.675, (float) $c['mid'], 0.0001);
        $this->assertSame('single', $c['method']);
        $this->assertSame(1, $c['count']);
        $this->assertSame(['wise'], array_keys($c['sources']));
        $this->assertLessThan(0.1, abs($c['deviation']), 'vs BSH 91.65');
    }

    public function test_no_data_when_no_selected_source_is_enabled(): void
    {
        $this->useSource('wise', false, true); // i shënuar por joaktiv
        $this->ref('wise', '91.675');

        $c = $this->market()->consensus()->get($this->eur->id);

        $this->assertNull($c['mid']);
        $this->assertSame('no_data', $c['reason']);
    }

    public function test_median_of_three_sources(): void
    {
        foreach (['wise', 'floatrates', 'coinbase'] as $code) {
            $this->useSource($code);
        }

        $this->ref('wise', '91.70');
        $this->ref('floatrates', '91.60');
        $this->ref('coinbase', '91.80');

        $c = $this->market()->consensus()->get($this->eur->id);

        $this->assertEqualsWithDelta(91.70, (float) $c['mid'], 0.0001);
        $this->assertSame('median', $c['method']);
        $this->assertSame(3, $c['count']);
        $this->assertSame([], $c['excluded']);
    }

    public function test_outlier_is_dropped_and_even_median_is_the_mean_of_the_middle_two(): void
    {
        foreach (['wise', 'floatrates', 'coinbase'] as $code) {
            $this->useSource($code);
        }

        $this->ref('wise', '91.70');
        $this->ref('floatrates', '91.60');
        $this->ref('coinbase', '95.00'); // +3.6% nga mediana 91.70 → hidhet

        $c = $this->market()->consensus()->get($this->eur->id);

        $this->assertArrayHasKey('coinbase', $c['excluded']);
        $this->assertSame(2, $c['count']);
        $this->assertEqualsWithDelta(91.65, (float) $c['mid'], 0.0001);
    }

    public function test_stale_sources_are_excluded(): void
    {
        $this->useSource('wise');
        $this->useSource('floatrates');
        $this->ref('wise', '91.70', now()->subDays(5));    // brenda dritares së latest() (7 ditë), por më i vjetër se 2 ditë
        $this->ref('floatrates', '91.60');

        $c = $this->market()->consensus()->get($this->eur->id);

        $this->assertEqualsWithDelta(91.60, (float) $c['mid'], 0.0001);
        $this->assertSame('single', $c['method']);
        $this->assertArrayHasKey('wise', $c['excluded']);
    }

    public function test_minimum_sources_requirement(): void
    {
        $this->useSource('wise');
        $this->ref('wise', '91.70');
        $this->market()->putSetting('min_sources', '2');

        $c = $this->market()->consensus()->get($this->eur->id);

        $this->assertNull($c['mid']);
        $this->assertSame('insufficient', $c['reason']);
    }

    public function test_recommendation_modes(): void
    {
        $m = $this->market();

        // last: marzhet e publikimit të fundit kundrejt BSH (−0.70% / +0.50%) mbi MID 91.70
        $current = $this->published('91.00', '92.00', '-0.7000', '0.5000');
        $m->putSetting('margin_mode', 'last');
        $rec = $m->recommend($this->eur, '91.70', $current);
        $this->assertSame(['buy' => '91.06', 'sell' => '92.16', 'mode' => 'last'], $rec);

        // percent: blerja MID×(1−0.5%), shitja MID×(1+0.5%)
        DB::table('fx_market_margins')->insert(['currency_id' => $this->eur->id, 'buy_pct' => '0.5', 'sell_pct' => '0.5', 'created_at' => now(), 'updated_at' => now()]);
        $m->putSetting('margin_mode', 'percent');
        $this->assertSame(['buy' => '91.24', 'sell' => '92.16', 'mode' => 'percent'], $m->recommend($this->eur, '91.70', null));

        // lek: MID − 0.40 / MID + 0.60
        DB::table('fx_market_margins')->where('currency_id', $this->eur->id)->update(['buy_lek' => '0.4', 'sell_lek' => '0.6']);
        $m->putSetting('margin_mode', 'lek');
        $this->assertSame(['buy' => '91.30', 'sell' => '92.30', 'mode' => 'lek'], $m->recommend($this->eur, '91.70', null));
    }

    public function test_general_margin_is_the_fallback_when_the_mode_has_no_basis(): void
    {
        $m = $this->market();

        // s'ka publikim të mëparshëm (last) dhe s'ka marzhe për monedhën → MID − 0.50 / MID + 0.50 (parazgjedhja)
        $m->putSetting('margin_mode', 'last');
        $this->assertSame(['buy' => '91.20', 'sell' => '92.20', 'mode' => 'default'], $m->recommend($this->eur, '91.70', null));

        // mënyra "lek" pa marzhe për monedhën → i njëjti fallback
        $m->putSetting('margin_mode', 'lek');
        $this->assertSame(['buy' => '91.20', 'sell' => '92.20', 'mode' => 'default'], $m->recommend($this->eur, '91.70', null));

        // marzhi i përgjithshëm i ndryshuar (blerje −0.30 / shitje +0.70)
        $m->putSetting('default_buy_lek', '0.3');
        $m->putSetting('default_sell_lek', '0.7');
        $this->assertSame(['buy' => '91.40', 'sell' => '92.40', 'mode' => 'default'], $m->recommend($this->eur, '91.70', null));

        // marzhi i vet i monedhës ka përparësi mbi të përgjithshmin
        DB::table('fx_market_margins')->insert(['currency_id' => $this->eur->id, 'buy_lek' => '0.4', 'sell_lek' => '0.6', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(['buy' => '91.30', 'sell' => '92.30', 'mode' => 'lek'], $m->recommend($this->eur, '91.70', null));
    }

    public function test_recommendation_is_null_without_basis_or_with_invalid_margins(): void
    {
        $m = $this->market();

        // fallback i fikur (0 / 0 → blerja = shitja = e pavlefshme)
        $m->putSetting('default_buy_lek', '0');
        $m->putSetting('default_sell_lek', '0');
        $m->putSetting('margin_mode', 'last');
        $this->assertNull($m->recommend($this->eur, '91.70', null), 'pa publikim të mëparshëm s\'ka marzhe "last"');
        $this->assertNull($m->recommend($this->eur, null, null), 'pa MID');

        DB::table('fx_market_margins')->insert(['currency_id' => $this->eur->id, 'buy_pct' => '0', 'sell_pct' => '0', 'created_at' => now(), 'updated_at' => now()]);
        $m->putSetting('margin_mode', 'percent');
        $this->assertNull($m->recommend($this->eur, '91.70', null), 'blerja = shitja është e pavlefshme');
    }

    public function test_drift_is_measured_against_the_middle_of_the_published_rate(): void
    {
        $this->useSource('wise');
        $this->ref('wise', '91.70');
        $this->published('90.00', '91.00'); // mesi 90.50 → MID 91.70 është +1.33%

        $rows = collect($this->market()->overview())->first(fn ($r) => $r['currency']->id === $this->eur->id);

        $this->assertEqualsWithDelta(1.33, $rows['drift'], 0.01);
        $this->assertCount(1, $this->market()->drifting());
    }

    public function test_small_drift_below_threshold_is_not_reported(): void
    {
        $this->useSource('wise');
        $this->ref('wise', '91.70');
        $this->published('91.20', '92.10'); // mesi 91.65 → +0.05%

        $this->assertCount(0, $this->market()->drifting());
        $this->assertSame([], $this->market()->notifyDrift());
    }

    public function test_notification_is_sent_once_per_state(): void
    {
        $this->useSource('wise');
        $this->ref('wise', '91.70');
        $this->published('90.00', '91.00', '-1.8000', '-0.7000');

        $first = $this->market()->notifyDrift();
        $second = $this->market()->notifyDrift();

        $this->assertCount(1, $first);
        $this->assertStringContainsString('EUR', $first[0]);
        $this->assertStringContainsString('91.7', $first[0]);
        $this->assertCount(1, $second, 'lexohet e njëjta lëvizje, por njoftimi nuk dërgohet përsëri');
        $this->assertSame(1, FxAlert::query()->where('type', 'market_moved')->count());
    }

    public function test_import_of_a_selected_source_triggers_the_drift_check(): void
    {
        $this->setSourceSecret('wise', 'test-token');
        $this->useSource('wise');
        $this->published('90.00', '91.00');

        Http::fake(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return Http::response([['rate' => 91.7, 'source' => $q['source'], 'target' => 'ALL', 'time' => now()->toIso8601String()]]);
        });

        $result = app(SourceSyncService::class)->run('wise', false, 'cron');

        $this->assertContains($result['status'], ['ok', 'partial']);
        $this->assertSame(1, FxAlert::query()->where('type', 'market_moved')->count());
    }

    public function test_dry_run_does_not_trigger_notifications(): void
    {
        $this->setSourceSecret('wise', 'test-token');
        $this->useSource('wise');
        $this->published('90.00', '91.00');

        Http::fake(fn ($request) => Http::response([['rate' => 91.7, 'source' => 'EUR', 'target' => 'ALL', 'time' => now()->toIso8601String()]]));

        app(SourceSyncService::class)->run('wise', true, 'user');

        $this->assertSame(0, FxAlert::query()->where('type', 'market_moved')->count());
    }
}
