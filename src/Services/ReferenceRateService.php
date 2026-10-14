<?php

namespace Botble\EmironFx\Services;

use Botble\EmironFx\Models\FxCurrency;
use Botble\EmironFx\Models\FxReferenceRate;
use Botble\EmironFx\Services\Sources\JsonRateSource;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Referenca e tregut — JO kurs zyrtar. Dy burime pa çelës:
 *
 *  erapi — ExchangeRate-API open access (open.er-api.com/v6/latest/ALL):
 *          X për 1 Lek → Lek për X = 1 ÷ kursi. Përditësim ditor.
 *          Kushti i tyre: atribuim kur kurset shfaqen publikisht (këtu vetëm panel).
 *  ecb   — BQE përmes Frankfurter (api.frankfurter.dev/v1/latest?base=EUR), ~16:00 CET.
 *          BQE s'ka ALL: Lek për X = (EUR i BSH-së) ÷ (X për 1 EUR sipas BQE).
 *          Mat konsistencën e kryqëzimeve (USD, GBP, CHF…) kundrejt EUR-it zyrtar.
 *
 * Kontrolli i shmangies së zyrës mbetet VETËM kundrejt BSH-së.
 */
class ReferenceRateService
{
    public const SOURCES = ['erapi', 'ecb', 'fawaz'];

    public function __construct(private readonly FxRateService $rates)
    {
    }

    /**
     * @return array{date: Carbon, rates: array<string, array{rate: string, basis: string}>}
     */
    public function fetch(string $source): array
    {
        return match ($source) {
            'erapi' => $this->fetchErApi(),
            'ecb' => $this->fetchEcb(),
            'fawaz' => $this->fetchFawaz(),
            default => $this->fetchJson($source),
        };
    }

    /** Burim JSON i konfigurueshëm nga regjistri (0.8.1). */
    private function fetchJson(string $source): array
    {
        $definition = app(SourceRegistry::class)->find($source);

        if (! $definition || $definition['driver'] !== 'json') {
            throw new RuntimeException("Burim i panjohur: {$source}");
        }

        // token-i (nëse kërkohet) dekriptohet këtu, vetëm për këtë kërkesë; s'hyn në regjistër/log
        if (! empty($definition['needs_secret'])) {
            $definition['secret'] = app(SourceRegistry::class)->secret($source);
        }

        $result = app(JsonRateSource::class)->fetch($definition, FxCurrency::query()->active()->pluck('code')->all());

        return ['date' => $result['date'], 'rates' => $result['rates'], 'errors' => $result['errors']];
    }

    /** @return array{saved: int, unchanged: int} */
    public function record(string $source, array $fetched): array
    {
        $currencies = FxCurrency::query()->get()->keyBy('code');
        $out = ['saved' => 0, 'unchanged' => 0];
        $date = $fetched['date']->toDateString();

        DB::transaction(function () use ($source, $fetched, $currencies, $date, &$out): void {
            foreach ($fetched['rates'] as $code => $item) {
                $currency = $currencies->get($code);

                if (! $currency) {
                    continue;
                }

                // Kursi i burimit është për 1 njësi; e kthejmë në njësinë e zyrës (p.sh. 100 JPY).
                $rate = bcmul($item['rate'], (string) $currency->unit, 6);

                $last = FxReferenceRate::query()
                    ->where('currency_id', $currency->id)
                    ->where('source', $source)
                    ->whereDate('rate_date', $date)
                    ->orderByDesc('id')
                    ->first();

                if ($last && bccomp((string) $last->rate, $rate, 6) === 0) {
                    $out['unchanged']++;
                    continue;
                }

                FxReferenceRate::query()->create([
                    'currency_id' => $currency->id,
                    'source' => $source,
                    'rate_date' => $date,
                    'rate' => $rate,
                    'basis' => mb_substr($item['basis'], 0, 120),
                ]);

                $out['saved']++;
            }
        });

        return $out;
    }

    /**
     * Referenca e fundit për çdo burim (brenda 7 ditëve).
     *
     * @return Collection<string, Collection<int, FxReferenceRate>> source => (currency_id => rreshti)
     */
    public function latest(): Collection
    {
        return FxReferenceRate::query()
            ->whereDate('rate_date', '>=', now()->subDays(7))
            ->orderByDesc('rate_date')
            ->orderByDesc('id')
            ->get()
            ->groupBy('source')
            ->map(fn (Collection $rows) => $rows->unique('currency_id')->keyBy('currency_id'));
    }

    /**
     * Çfarë burimesh ka sistemi dhe cili është aktiv (vetëm lexim, për panelin).
     *
     * @return array<string, array{label: string, url: string, enabled: bool, last_saved: ?string}>
     */
    public function sourceInfo(): array
    {
        $info = [];

        foreach (app(SourceRegistry::class)->all() as $code => $source) {
            if ($source['role'] === 'official') {
                continue;
            }

            $info[$code] = [
                'label' => $source['name'],
                'url' => (string) $source['url'],
                'enabled' => (bool) $source['enabled'],
                'last_saved' => FxReferenceRate::query()->where('source', $code)->max('created_at'),
            ];
        }

        return $info;
    }

    public function label(string $source): string
    {
        return match ($source) {
            'erapi' => 'ExchangeRate-API',
            'ecb' => 'BQE',
            'fawaz' => 'Currency-API',
            default => app(SourceRegistry::class)->label($source),
        };
    }

    private function fetchErApi(): array
    {
        $url = (string) config('plugins.emiron-fx.general.reference_erapi_url');
        $json = $this->getJson($url);

        if (($json['result'] ?? null) !== 'success' || ! is_array($json['rates'] ?? null)) {
            throw new RuntimeException('ExchangeRate-API: përgjigje e pavlefshme.');
        }

        $date = isset($json['time_last_update_unix'])
            ? Carbon::createFromTimestamp((int) $json['time_last_update_unix'])->setTimezone(config('app.timezone'))
            : now();

        $rates = [];

        foreach ($json['rates'] as $code => $perLek) {
            if (! is_numeric($perLek) || (float) $perLek <= 0 || $code === 'ALL') {
                continue;
            }

            $rates[strtoupper($code)] = [
                'rate' => bcdiv('1', $this->decimal($perLek), 10),
                'basis' => 'open.er-api.com ' . $date->format('d.m.Y H:i'),
            ];
        }

        return ['date' => $date, 'rates' => $rates];
    }

    private function fetchEcb(): array
    {
        $eurCurrency = FxCurrency::query()->where('code', 'EUR')->first();
        $eurOfficial = $eurCurrency ? $this->rates->officialRates()->get($eurCurrency->id) : null;

        if (! $eurOfficial) {
            throw new RuntimeException('BQE: mungon kursi zyrtar i EUR nga BSH (i nevojshëm për kryqëzimin).');
        }

        $url = (string) config('plugins.emiron-fx.general.reference_ecb_url');
        $json = $this->getJson($url);

        if (($json['base'] ?? null) !== 'EUR' || ! is_array($json['rates'] ?? null) || empty($json['date'])) {
            throw new RuntimeException('BQE/Frankfurter: përgjigje e pavlefshme.');
        }

        $eurLek = bcdiv((string) $eurOfficial->rate, (string) max(1, (int) $eurCurrency->unit), 10);
        $rates = [];

        foreach ($json['rates'] as $code => $perEur) {
            if (! is_numeric($perEur) || (float) $perEur <= 0) {
                continue;
            }

            $rates[strtoupper($code)] = [
                'rate' => bcdiv($eurLek, $this->decimal($perEur), 10),
                'basis' => sprintf('BQE %s × EUR BSH %s', $json['date'], $eurOfficial->rate_date->format('d.m.Y')),
            ];
        }

        return ['date' => Carbon::parse($json['date'], config('app.timezone')), 'rates' => $rates];
    }

    /**
     * fawaz — @fawazahmed0/currency-api (CDN jsDelivr, pa çelës, ka ALL, ditor).
     * Përgjigja: {"date":"YYYY-MM-DD","all":{"eur":0.0108,...}} — X për 1 Lek → Lek për X = 1 ÷ kursi.
     */
    private function fetchFawaz(): array
    {
        $json = $this->getJson((string) config('plugins.emiron-fx.general.reference_fawaz_url'));

        if (! is_array($json['all'] ?? null) || empty($json['date'])) {
            throw new RuntimeException('Currency-API: përgjigje e pavlefshme.');
        }

        $rates = [];

        foreach ($json['all'] as $code => $perLek) {
            $code = strtoupper((string) $code);

            // Vetëm kodet e monedhave (3 shkronja); API-ja përmban edhe kripto-asete me kode të tjera.
            if (! preg_match('/^[A-Z]{3}$/', $code) || $code === 'ALL' || ! is_numeric($perLek) || (float) $perLek <= 0) {
                continue;
            }

            $rates[$code] = [
                'rate' => bcdiv('1', $this->decimal($perLek), 10),
                'basis' => 'currency-api ' . $json['date'],
            ];
        }

        return ['date' => Carbon::parse($json['date'], config('app.timezone')), 'rates' => $rates];
    }

    private function getJson(string $url): array
    {
        try {
            $response = Http::timeout(20)->retry(2, 2000)->acceptJson()
                ->withHeaders(['User-Agent' => 'EmironFX/0.3 (+reference rates)'])
                ->get($url);
        } catch (Throwable $exception) {
            throw new RuntimeException("{$url}: " . $exception->getMessage(), 0, $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException("{$url}: HTTP {$response->status()}");
        }

        return (array) $response->json();
    }

    /** 1.0E-5 → "0.0000100000" (bcmath s'pranon notacion shkencor). */
    private function decimal(mixed $value): string
    {
        return rtrim(rtrim(sprintf('%.12F', (float) $value), '0'), '.') ?: '0';
    }
}
