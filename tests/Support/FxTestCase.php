<?php

namespace Botble\EmironFx\Tests\Support;

use Botble\EmironFx\Models\FxCurrency;
use Botble\EmironFx\Models\FxOfficialRate;
use Botble\EmironFx\Models\FxRate;
use Botble\EmironFx\Models\FxTill;
use Botble\EmironFx\Models\FxTillSession;
use Botble\EmironFx\Services\FxHoldingService;
use Botble\EmironFx\Services\FxTillService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Baza e testeve Feature (0.7.6): s'duhet përdorues i ri, bazë e re, as .env.testing.
 *
 * Testet përdorin të njëjtën lidhje të bazës, por me PREFIKS të tabelave `fxtest_` (p.sh. `fxtest_fx_transactions`).
 * Tabelat krijohen vetë herën e parë (migrimet e plugin-it, përfshirë `fxtest_migrations`), dhe asnjë tabelë e
 * prodhimit (pa prefiks) nuk preket kurrë.
 *
 * SIGURI (dyfishe):
 *  - prefiksi vendoset me kod para çdo query-je dhe kontrollohet që përmban "test" (përndryshe testi hedh përjashtim);
 *  - APP_ENV duhet të jetë "testing" (e vendos phpunit.xml.dist);
 *  - çdo test ekzekutohet brenda një transaksioni që kthehet mbrapsht (DatabaseTransactions).
 * Tabelat e testit hiqen kur të duash me: DROP TABLE për çdo `fxtest\_%` (shih tests/README.md).
 */
abstract class FxTestCase extends TestCase
{
    public const TABLE_PREFIX = 'fxtest_';

    use DatabaseTransactions {
        beginDatabaseTransaction as private beginFxTransaction;
    }

    private static bool $schemaReady = false;

    public function createApplication()
    {
        // .../platform/plugins/emiron-fx/tests/Support → rrënja e projektit = 5 nivele sipër
        $app = require dirname(__DIR__, 5) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        // Prefiks i veçantë për tabelat: lidhja rindërtohet që të gjitha query-t (Eloquent, DB::table, Schema) e përdorin.
        $default = $app['config']->get('database.default');
        $app['config']->set("database.connections.{$default}.prefix", self::TABLE_PREFIX);
        $app['db']->purge($default);

        return $app;
    }

    public function beginDatabaseTransaction(): void
    {
        $this->guardTestDatabase();
        $this->ensureSchema();
        $this->beginFxTransaction();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function guardTestDatabase(): void
    {
        $env = $this->app->environment();
        $prefix = (string) DB::connection()->getTablePrefix();
        $db = (string) DB::connection()->getDatabaseName();

        if ($env !== 'testing' || ! str_contains(strtolower($prefix), 'test')) {
            throw new RuntimeException("Testet Feature refuzojnë të ekzekutohen: APP_ENV={$env}, prefiksi=\"{$prefix}\", baza=\"{$db}\". Prefiksi i tabelave duhet të përmbajë \"test\".");
        }
    }

    /** Krijon tabelat FX me prefiks testi herën e parë (jashtë transaksionit: DDL në MySQL e mbyll transaksionin). */
    private function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        // Gjithmonë `migrate` (një herë për proces): migrimet e reja të plugin-it (p.sh. 0.8.x) zbatohen edhe
        // në tabelat `fxtest_*` ekzistuese; Laravel ekzekuton vetëm ato që mungojnë.
        Artisan::call('migrate', [
            '--path' => dirname(__DIR__, 2) . '/database/migrations',
            '--realpath' => true,
            '--force' => true,
        ]);

        if (! Schema::hasTable('fx_transactions')) {
            throw new RuntimeException('Migrimet FX nuk krijuan tabelat e testit. Dalja: ' . Artisan::output());
        }

        self::$schemaReady = true;
    }

    // ───────────── Fixtures ─────────────

    protected function makeOffice(string $code = 'T1'): int
    {
        $id = DB::table('fx_offices')->where('code', $code)->value('id');

        return $id ?: DB::table('fx_offices')->insertGetId([
            'name' => 'Zyra e testit ' . $code, 'code' => $code, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function makeTill(int $officeId, int $userId = 9001, string $name = 'Arka e testit'): FxTill
    {
        return FxTill::query()->create(['office_id' => $officeId, 'name' => $name, 'user_id' => $userId, 'is_active' => true]);
    }

    protected function currency(string $code, int $unit = 1, int $decimals = 2, bool $active = true): FxCurrency
    {
        $row = FxCurrency::query()->where('code', $code)->first();

        if ($row) {
            $row->forceFill(['unit' => $unit, 'decimals' => $decimals, 'is_active' => $active])->save();

            return $row->fresh();
        }

        return FxCurrency::query()->create([
            'code' => $code, 'name' => $code, 'decimals' => $decimals, 'unit' => $unit,
            'is_active' => $active, 'sort_order' => 50,
        ]);
    }

    protected function setRate(FxCurrency $currency, string $buy, string $sell, ?Carbon $effective = null): FxRate
    {
        return FxRate::query()->create([
            'currency_id' => $currency->id, 'buy_rate' => $buy, 'sell_rate' => $sell,
            'effective_at' => ($effective ?? now())->copy(), 'batch_uuid' => (string) Str::uuid(),
        ]);
    }

    protected function setOfficial(FxCurrency $currency, string $rate, ?Carbon $date = null): FxOfficialRate
    {
        return FxOfficialRate::query()->create([
            'currency_id' => $currency->id, 'rate_date' => ($date ?? now())->toDateString(), 'rate' => $rate,
        ]);
    }

    /** Hap arkën (numërimi i parë = gjendja fillestare). */
    protected function openTill(FxTill $till, array $counts, ?string $note = null): FxTillSession
    {
        return app(FxTillService::class)->open($till, (int) $till->user_id, $counts, $note);
    }

    /** @return array<string,string> gjendja e arkës për monedhë */
    protected function balances(FxTill $till): array
    {
        return app(FxHoldingService::class)->balances($till->id);
    }

    /** Vendos (ose heq, null) token-in e një burimi JSON, i enkriptuar siç e ruan paneli (0.8.3). */
    protected function setSourceSecret(string $code, ?string $token): void
    {
        DB::table('fx_rate_sources')->where('code', $code)->update([
            'secret' => $token === null ? null : \Botble\EmironFx\Support\FxCrypto::encrypt($token),
        ]);

        app(\Botble\EmironFx\Services\SourceRegistry::class)->refresh();
    }

    protected function makePartner(string $code = 'TST', float $lowAlert = 0): int
    {
        return DB::table('fx_partners')->insertGetId([
            'name' => 'Partner ' . $code, 'code' => $code, 'prepaid_low_alert' => $lowAlert, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<int,string> $currencies */
    protected function makeService(int $partnerId, string $name, string $direction, string $settlement, array $currencies = ['ALL'], bool $active = true): int
    {
        return DB::table('fx_partner_services')->insertGetId([
            'partner_id' => $partnerId, 'name' => $name, 'direction' => $direction, 'settlement' => $settlement,
            'currencies' => json_encode($currencies), 'is_active' => $active, 'sort_order' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function makeBankAccount(string $currency = 'ALL', string $opening = '0', bool $active = true): \Botble\EmironFx\Models\FxBankAccount
    {
        $id = DB::table('fx_bank_accounts')->insertGetId([
            'bank_name' => 'Banka e testit', 'iban' => 'AL00TEST', 'currency_code' => $currency, 'opening_balance' => $opening,
            'opening_date' => now()->subMonth()->toDateString(), 'is_active' => $active,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return \Botble\EmironFx\Models\FxBankAccount::query()->findOrFail($id);
    }

    /** Një arkë gati për veprime: zyrë, arkëtar, EUR aktiv me kurs 100/102 dhe kurs BSH 101, seancë e hapur me Lek. */
    protected function readyTill(string $lek = '1000000', string $eur = '0'): array
    {
        $office = $this->makeOffice();
        $till = $this->makeTill($office);
        $eurCurrency = $this->currency('EUR');
        $this->setOfficial($eurCurrency, '101');
        $this->setRate($eurCurrency, '100', '102');
        $session = $this->openTill($till, ['ALL' => $lek, 'EUR' => $eur]);

        return [$till, $session, $eurCurrency, $office];
    }
}
