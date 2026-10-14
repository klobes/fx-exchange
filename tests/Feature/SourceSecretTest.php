<?php

namespace Botble\EmironFx\Tests\Feature;

use Botble\EmironFx\Models\FxRateSource;
use Botble\EmironFx\Models\FxSourceRun;
use Botble\EmironFx\Services\SourceRegistry;
use Botble\EmironFx\Services\SourceSyncService;
use Botble\EmironFx\Support\FxCrypto;
use Botble\EmironFx\Tests\Support\FxTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Token-i i API-së (Wise): ruhet i enkriptuar te DB, dekriptohet vetëm kur përdoret, s'del askund (0.8.3). */
class SourceSecretTest extends FxTestCase
{
    private const TOKEN = 'wise-secret-token-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        config(['plugins.emiron-fx.general.source_retry_sleep_ms' => 0]);
        $this->currency('EUR');
        $this->setOfficial($this->currency('EUR'), '91.65');
    }

    public function test_token_is_stored_encrypted_and_not_in_plaintext(): void
    {
        $this->setSourceSecret('wise', self::TOKEN);

        $stored = (string) DB::table('fx_rate_sources')->where('code', 'wise')->value('secret');

        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString(self::TOKEN, $stored);
        $this->assertSame(self::TOKEN, FxCrypto::decrypt($stored));
    }

    public function test_registry_exposes_only_presence_never_the_value(): void
    {
        $this->setSourceSecret('wise', self::TOKEN);

        $registry = app(SourceRegistry::class);
        $descriptor = $registry->find('wise');

        $this->assertTrue($descriptor['needs_secret']);
        $this->assertTrue($descriptor['has_secret']);
        $this->assertArrayNotHasKey('secret', $descriptor);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($registry->all()->all()));
        $this->assertSame(self::TOKEN, $registry->secret('wise'), 'dekriptohet vetëm kur kërkohet');
    }

    public function test_model_never_serializes_the_secret(): void
    {
        $this->setSourceSecret('wise', self::TOKEN);

        $array = FxRateSource::query()->where('code', 'wise')->firstOrFail()->toArray();

        $this->assertArrayNotHasKey('secret', $array);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($array));
    }

    public function test_clearing_the_token(): void
    {
        $this->setSourceSecret('wise', self::TOKEN);
        $this->setSourceSecret('wise', null);

        $registry = app(SourceRegistry::class);

        $this->assertFalse($registry->find('wise')['has_secret']);
        $this->assertNull($registry->secret('wise'));
    }

    public function test_sources_without_auth_do_not_need_a_secret(): void
    {
        $descriptor = app(SourceRegistry::class)->find('floatrates');

        $this->assertFalse($descriptor['needs_secret']);
    }

    public function test_the_decrypted_token_is_sent_as_bearer_and_never_logged(): void
    {
        $this->setSourceSecret('wise', self::TOKEN);

        Http::fake(fn ($request) => Http::response([['rate' => 91.7, 'source' => 'EUR', 'target' => 'ALL', 'time' => now()->toIso8601String()]]));

        $result = app(SourceSyncService::class)->run('wise', false, 'cron');

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer ' . self::TOKEN));
        $this->assertContains($result['status'], ['ok', 'partial']);

        $logged = json_encode(FxSourceRun::query()->get()->toArray());
        $this->assertStringNotContainsString(self::TOKEN, $logged);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($result));
    }

    public function test_a_failed_request_does_not_leak_the_token_in_the_error(): void
    {
        $this->setSourceSecret('wise', self::TOKEN);
        Http::fake(fn ($request) => Http::response(['error' => 'unauthorized'], 401));

        $result = app(SourceSyncService::class)->run('wise', false, 'cron');

        $this->assertSame('failed', $result['status']);
        $this->assertStringNotContainsString(self::TOKEN, (string) $result['error']);
        $this->assertStringNotContainsString(self::TOKEN, json_encode(FxSourceRun::query()->get()->toArray()));
    }

    public function test_missing_token_fails_before_any_request(): void
    {
        $this->setSourceSecret('wise', null);
        Http::fake();

        $result = app(SourceSyncService::class)->run('wise', false, 'cron');

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('Token-i mungon', (string) $result['error']);
        Http::assertNothingSent();
    }
}
