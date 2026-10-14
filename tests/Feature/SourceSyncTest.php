<?php

namespace Botble\EmironFx\Tests\Feature;

use Botble\EmironFx\Models\FxOfficialRate;
use Botble\EmironFx\Models\FxReferenceRate;
use Botble\EmironFx\Models\FxSourceRun;
use Botble\EmironFx\Services\SourceRegistry;
use Botble\EmironFx\Services\SourceSyncService;
use Botble\EmironFx\Tests\Support\FxTestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Sinkronizimi i burimeve (0.8.1): adaptori JSON (map/entries/list/per_pair), regjistri i përpjekjeve,
 * kontrolli i cilësisë (kurse të largëta nga BSH hidhen), fallback, shëndeti.
 * Përgjigjet e burimeve simulohen (Http::fake) me forma të marra nga përgjigjet reale të testuara.
 */
class SourceSyncTest extends FxTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['plugins.emiron-fx.general.source_retry_sleep_ms' => 0]); // pa pritje midis riprovave në teste

        // kurse zyrtare "reale" (BSH) për krahasim: EUR 91.65, USD 81.70
        $eur = $this->currency('EUR');
        $usd = $this->currency('USD');
        $this->setOfficial($eur, '91.65');
        $this->setOfficial($usd, '81.70');
    }

    private function sync(): SourceSyncService
    {
        return app(SourceSyncService::class);
    }

    private function floatRatesBody(): array
    {
        return [
            'usd' => ['code' => 'USD', 'alphaCode' => 'USD', 'rate' => 0.01223095, 'inverseRate' => 81.76, 'date' => 'Fri, 9 Oct 2026 20:55:03 GMT'],
            'eur' => ['code' => 'EUR', 'alphaCode' => 'EUR', 'rate' => 0.01090813, 'inverseRate' => 91.67, 'date' => 'Fri, 9 Oct 2026 20:55:03 GMT'],
            'xau' => ['code' => 'XAU', 'alphaCode' => 'XAU', 'rate' => 0.00000028, 'inverseRate' => 3500000.0, 'date' => 'Fri, 9 Oct 2026 20:55:03 GMT'],
        ];
    }

    public function test_entries_mode_dry_run_reads_without_saving_and_is_logged(): void
    {
        Http::fake(['www.floatrates.com/*' => Http::response($this->floatRatesBody())]);

        $r = $this->sync()->run('floatrates', true, 'cli');

        $this->assertSame('ok', $r['status']);
        $this->assertTrue($r['dry_run']);
        $this->assertSame('2026-10-09', $r['data_date']);
        $this->assertSame('91.67', $r['rows']['EUR']['rate']);
        $this->assertSame('81.76', $r['rows']['USD']['rate']);
        $this->assertArrayNotHasKey('XAU', $r['rows'], 'monedhë që zyra s\'e ka');
        $this->assertLessThan(0.1, $r['rows']['EUR']['deviation']);

        $this->assertSame(0, FxReferenceRate::query()->where('source', 'floatrates')->count(), 'prova s\'ruan asgjë');

        $run = FxSourceRun::query()->where('source_code', 'floatrates')->firstOrFail();
        $this->assertTrue($run->dry_run);
        $this->assertSame('ok', $run->status);
        $this->assertSame('cli', $run->triggered_by);
    }

    public function test_import_saves_then_second_import_is_unchanged(): void
    {
        Http::fake(['www.floatrates.com/*' => Http::response($this->floatRatesBody())]);

        $first = $this->sync()->run('floatrates', false, 'user', 5);
        $second = $this->sync()->run('floatrates', false, 'user', 5);

        $this->assertSame(2, $first['saved']);
        $this->assertSame(0, $first['unchanged']);
        $this->assertSame(0, $second['saved']);
        $this->assertSame(2, $second['unchanged']);
        $this->assertSame(2, FxReferenceRate::query()->where('source', 'floatrates')->count());
        $this->assertSame('91.670000', (string) FxReferenceRate::query()->where('source', 'floatrates')->where('currency_id', $this->currency('EUR')->id)->value('rate'));

        $runs = FxSourceRun::query()->where('source_code', 'floatrates')->orderBy('id')->get();
        $this->assertCount(2, $runs);
        $this->assertSame(5, (int) $runs[0]->user_id);
        $this->assertSame(2, (int) $runs[0]->rows_saved);
        $this->assertSame(2, (int) $runs[1]->rows_unchanged);
    }

    public function test_map_mode_inverts_rates_per_lek(): void
    {
        Http::fake(['api.coinbase.com/*' => Http::response(['data' => ['currency' => 'ALL', 'rates' => [
            'EUR' => '0.01091789434503', 'USD' => '0.0122308039213755', 'BTC' => '0.0000001', 'ALL' => '1',
        ]]])]);

        $r = $this->sync()->run('coinbase', true);

        $this->assertSame('ok', $r['status']);
        $this->assertEqualsWithDelta(91.59, (float) $r['rows']['EUR']['rate'], 0.01);
        $this->assertEqualsWithDelta(81.76, (float) $r['rows']['USD']['rate'], 0.01);
        $this->assertArrayNotHasKey('ALL', $r['rows']);
    }

    public function test_list_mode_finds_the_list_even_when_nested_and_filters_quote(): void
    {
        Http::fake(['allratestoday.com/*' => Http::response(['source' => 'BOA', 'data' => ['rates' => [
            ['base' => 'EUR', 'quote' => 'ALL', 'rate' => 91.65, 'rate_date' => '2026-10-09', 'type' => 'reference'],
            ['base' => 'USD', 'quote' => 'ALL', 'rate' => 81.7, 'rate_date' => '2026-10-09', 'type' => 'reference'],
            ['base' => 'EUR', 'quote' => 'USD', 'rate' => 1.12, 'rate_date' => '2026-10-09', 'type' => 'cross'],
        ]]])]);

        $r = $this->sync()->run('boa_mirror', true);

        $this->assertSame('ok', $r['status']);
        $this->assertSame('2026-10-09', $r['data_date']);
        $this->assertSame('91.65', $r['rows']['EUR']['rate']);
        $this->assertSame('81.7', $r['rows']['USD']['rate']);
        $this->assertSame(2, $r['read'], 'çifti EUR/USD s\'është kurs kundrejt Lekut');
    }

    public function test_per_pair_mode_sends_bearer_token_and_reads_one_rate_per_active_currency(): void
    {
        $this->setSourceSecret('wise', 'test-token-123');

        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $rate = ['EUR' => 91.675, 'USD' => 82.0212][$q['source']] ?? null;

            return $rate === null
                ? Http::response(['error' => 'bad_request'], 400)
                : Http::response([['rate' => $rate, 'source' => $q['source'], 'target' => 'ALL', 'time' => '2026-10-12T12:32:01+0000']]);
        });

        $r = $this->sync()->run('wise', true);

        $this->assertSame('ok', $r['status']);
        $this->assertSame('91.675', $r['rows']['EUR']['rate']);
        $this->assertSame('82.0212', $r['rows']['USD']['rate']);
        $this->assertSame('2026-10-12', $r['data_date']);

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-token-123'));
        Http::assertSentCount(2);
    }

    public function test_per_pair_failure_of_one_currency_gives_partial(): void
    {
        $this->setSourceSecret('wise', 'test-token-123');

        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return $q['source'] === 'USD'
                ? Http::response(['error' => 'unsupported'], 400)
                : Http::response([['rate' => 91.675, 'source' => 'EUR', 'target' => 'ALL', 'time' => '2026-10-12T12:32:01+0000']]);
        });

        $r = $this->sync()->run('wise', false, 'cron');

        $this->assertSame('partial', $r['status']);
        $this->assertSame(1, $r['saved']);
        $this->assertNotEmpty($r['errors']);
        $this->assertStringContainsString('USD', implode(' ', $r['errors']));
    }

    public function test_missing_token_fails_with_a_clear_message_and_is_logged(): void
    {
        $this->setSourceSecret('wise', null);
        Http::fake();

        $r = $this->sync()->run('wise', false, 'cron');

        $this->assertSame('failed', $r['status']);
        $this->assertStringContainsString('Token-i mungon', (string) $r['error']);
        Http::assertNothingSent();

        $run = FxSourceRun::query()->where('source_code', 'wise')->firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->error);
    }

    public function test_rates_far_from_the_official_rate_are_dropped(): void
    {
        // EUR 0.02 → 50 Lek (45% larg nga BSH 91.65) hidhet; USD brenda pragut ruhet
        Http::fake(['api.coinbase.com/*' => Http::response(['data' => ['rates' => ['EUR' => '0.02', 'USD' => '0.0122']]])]);

        $r = $this->sync()->run('coinbase', false, 'cron');

        $this->assertSame('partial', $r['status']);
        $this->assertSame(1, $r['saved']);
        $this->assertCount(1, $r['skipped']);
        $this->assertStringContainsString('EUR', $r['skipped'][0]);
        $this->assertSame(1, FxReferenceRate::query()->where('source', 'coinbase')->count());
        $this->assertTrue($r['rows']['EUR']['skipped']);
    }

    public function test_all_rates_dropped_is_a_failure(): void
    {
        Http::fake(['api.coinbase.com/*' => Http::response(['data' => ['rates' => ['EUR' => '0.02', 'USD' => '0.05']]])]);

        $r = $this->sync()->run('coinbase', false, 'cron');

        $this->assertSame('failed', $r['status']);
        $this->assertSame(0, FxReferenceRate::query()->where('source', 'coinbase')->count());
    }

    public function test_http_error_is_a_logged_failure_and_saves_nothing(): void
    {
        Http::fake(['www.floatrates.com/*' => Http::response('server error', 500)]);

        $r = $this->sync()->run('floatrates', false, 'cron');

        $this->assertSame('failed', $r['status']);
        $this->assertStringContainsString('HTTP 500', (string) $r['error']);
        $this->assertSame(0, FxReferenceRate::query()->where('source', 'floatrates')->count());
        $this->assertSame('failed', FxSourceRun::query()->where('source_code', 'floatrates')->latest('id')->value('status'));
    }

    public function test_invalid_json_shape_is_a_failure(): void
    {
        Http::fake(['api.coinbase.com/*' => Http::response(['unexpected' => 'shape'])]);

        $r = $this->sync()->run('coinbase', false, 'cron');

        $this->assertSame('failed', $r['status']);
    }

    public function test_fallback_url_is_used_when_the_primary_fails(): void
    {
        DB::table('fx_rate_sources')->where('code', 'coinbase')->update(['fallback_url' => 'https://fallback.example/rates.json']);
        app(SourceRegistry::class)->refresh();

        Http::fake([
            'api.coinbase.com/*' => Http::response('down', 503),
            'fallback.example/*' => Http::response(['data' => ['rates' => ['EUR' => '0.0109', 'USD' => '0.01224']]]),
        ]);

        $r = $this->sync()->run('coinbase', true);

        $this->assertSame('ok', $r['status']);
        $this->assertArrayHasKey('EUR', $r['rows']);
    }

    public function test_official_source_imports_saves_rates_and_is_logged(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00'); // jo e njëjta ditë me publikimin: pa njoftime shmangieje

        $html = '<p>Last update: 12.10.2026 12:26:25</p><table>'
            . '<tr><th>Currency</th><th>Code</th><th>Rate</th></tr>'
            . '<tr><td>Euro</td><td>EUR</td><td>91.79</td></tr>'
            . '<tr><td>US Dollar</td><td>USD</td><td>82.05</td></tr></table>';
        Http::fake(['www.bankofalbania.org/*' => Http::response($html)]);

        $r = $this->sync()->run(SourceRegistry::OFFICIAL, false, 'user', 7);

        $this->assertSame('ok', $r['status']);
        $this->assertSame('2026-10-12', $r['data_date']);
        $this->assertSame(2, $r['saved']);
        $this->assertEqualsWithDelta(91.79, (float) $r['rows']['EUR']['rate'], 0.0001);
        $this->assertSame(1, FxOfficialRate::query()->where('currency_id', $this->currency('EUR')->id)->whereDate('rate_date', '2026-10-12')->count());

        $run = FxSourceRun::query()->where('source_code', SourceRegistry::OFFICIAL)->firstOrFail();
        $this->assertSame(7, (int) $run->user_id);
        $this->assertSame('ok', $run->status);
    }

    public function test_registry_lists_builtin_and_json_sources_and_enabled_codes(): void
    {
        config(['plugins.emiron-fx.general.reference_sources' => ['erapi']]);
        DB::table('fx_rate_sources')->where('code', 'floatrates')->update(['enabled' => true]);

        $registry = new SourceRegistry();
        $all = $registry->all();

        foreach (['boa_html', 'erapi', 'ecb', 'fawaz', 'floatrates', 'coinbase', 'wise', 'boa_mirror'] as $code) {
            $this->assertTrue($all->has($code), "Mungon burimi {$code}");
        }

        $this->assertSame('official', $all['boa_html']['role']);
        $this->assertSame('official_mirror', $all['boa_mirror']['role']);

        $enabled = $registry->enabledReferenceCodes();
        $this->assertContains('erapi', $enabled);
        $this->assertContains('floatrates', $enabled);
        $this->assertNotContains('ecb', $enabled);
        $this->assertNotContains('wise', $enabled);
        $this->assertNotContains('boa_html', $enabled, 'BSH-ja zyrtare s\'është burim referencë');
        $this->assertArrayHasKey('floatrates', $registry->comparisonLabels());
    }

    public function test_health_reflects_attempts(): void
    {
        DB::table('fx_rate_sources')->where('code', 'floatrates')->update(['enabled' => true]);
        $health = fn () => collect($this->sync()->overview())->firstWhere('code', 'floatrates')['health'];

        // ri-lexo regjistrin pas ndryshimit të DB-së
        app(SourceRegistry::class)->refresh();
        $this->assertSame('never', $health());

        Http::fake(['www.floatrates.com/*' => Http::response('boom', 500)]);
        $this->sync()->run('floatrates', false, 'cron');
        $this->assertSame('bad', $health(), 'përpjekje e dështuar pa sukses të mëparshëm');

        Http::fake(['www.floatrates.com/*' => Http::response($this->floatRatesBody())]);
        $this->sync()->run('floatrates', false, 'cron');
        $this->assertSame('ok', $health());

        DB::table('fx_rate_sources')->where('code', 'floatrates')->update(['enabled' => false]);
        app(SourceRegistry::class)->refresh();
        $this->assertSame('off', $health());
    }

    public function test_unknown_source_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sync()->run('nuk_ekziston');
    }
}
