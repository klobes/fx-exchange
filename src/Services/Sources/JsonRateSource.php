<?php

namespace Botble\EmironFx\Services\Sources;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Adaptor i përgjithshëm për burime JSON me URL (0.8.1): shtimi i një burimi të ri është konfigurim, jo kod.
 *
 * Çdo burim kthen kurs "Lek për 1 njësi valute" (si FxReferenceRate para shumëzimit me njësinë e zyrës).
 *
 * config.mode:
 *   map      — rates_path → objekt kod=>numër.            orientation: per_lek (X për 1 Lek) | lek_per_unit (Lek për 1 X)
 *   entries  — rates_path ('' = rrënja) → kod=>objekt;    entry_rate (fusha me kursin), entry_date (opsionale)
 *   list     — listë objektesh;                           base_field, rate_field, quote_field+quote_value (opsionale), date_field
 *   per_pair — një kërkesë për çdo monedhë aktive;        url me {code}; rate_path (p.sh. 0.rate), time_path (p.sh. 0.time)
 *
 * Të përbashkëta: date_path | date_format=unix (map), timeout, headers, auth {type: bearer, config_key: <çelës te config>}.
 * Kërkesa: timeout + retry; 429 → respekton Retry-After (deri 5 s) dhe provon një herë; fallback_url nëse e para dështon.
 */
class JsonRateSource
{
    /**
     * @param  array{code?: string, name?: string, url: string, fallback_url?: ?string, config?: array}  $source
     * @param  array<int, string>  $codes  kodet e monedhave aktive (vetëm për per_pair)
     * @return array{date: Carbon, rates: array<string, array{rate: string, basis: string}>, errors: array<int, string>}
     *
     * @throws RuntimeException
     */
    public function fetch(array $source, array $codes = []): array
    {
        $cfg = (array) ($source['config'] ?? []);
        // 0.8.3: token-i vjen i dekriptuar nga regjistri (nga DB e enkriptuar) vetëm për këtë thirrje; s'ruhet gjëkundi.
        $cfg['__secret'] = (string) ($source['secret'] ?? '');
        $mode = (string) ($cfg['mode'] ?? 'map');
        $label = (string) ($source['name'] ?? $source['code'] ?? 'json');

        return $mode === 'per_pair'
            ? $this->perPair($source, $cfg, $codes, $label)
            : $this->single($source, $cfg, $mode, $label);
    }

    private function single(array $source, array $cfg, string $mode, string $label): array
    {
        $json = $this->getJson((string) $source['url'], $source['fallback_url'] ?? null, $cfg);
        $orientation = (string) ($cfg['orientation'] ?? 'per_lek');
        $rates = [];
        $dates = [];

        if ($mode === 'map') {
            $map = $this->at($json, (string) ($cfg['rates_path'] ?? ''));

            if (! is_array($map)) {
                throw new RuntimeException("{$label}: rruga '" . ($cfg['rates_path'] ?? '') . "' s'u gjet në përgjigje.");
            }

            foreach ($map as $code => $value) {
                $this->addRate($rates, (string) $code, $value, $orientation);
            }

            if ($date = $this->parseDate(Arr::get($json, (string) ($cfg['date_path'] ?? '')), $cfg)) {
                $dates[] = $date;
            }
        } elseif ($mode === 'entries') {
            $map = $this->at($json, (string) ($cfg['rates_path'] ?? ''));

            if (! is_array($map)) {
                throw new RuntimeException("{$label}: rruga '" . ($cfg['rates_path'] ?? '') . "' s'u gjet në përgjigje.");
            }

            foreach ($map as $code => $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $this->addRate($rates, (string) $code, $entry[(string) ($cfg['entry_rate'] ?? 'rate')] ?? null, $orientation);

                if (isset($cfg['entry_date']) && ($date = $this->parseDate($entry[$cfg['entry_date']] ?? null, $cfg))) {
                    $dates[] = $date;
                }
            }
        } elseif ($mode === 'list') {
            $base = (string) ($cfg['base_field'] ?? 'base');
            $rateField = (string) ($cfg['rate_field'] ?? 'rate');
            $list = isset($cfg['list_path']) ? $this->at($json, (string) $cfg['list_path']) : $this->findList($json, $base, $rateField);

            if (! is_array($list)) {
                throw new RuntimeException("{$label}: s'u gjet lista e kurseve në përgjigje.");
            }

            foreach ($list as $item) {
                if (! is_array($item)) {
                    continue;
                }

                if (isset($cfg['quote_field']) && strtoupper((string) ($item[$cfg['quote_field']] ?? '')) !== strtoupper((string) ($cfg['quote_value'] ?? 'ALL'))) {
                    continue;
                }

                $this->addRate($rates, (string) ($item[$base] ?? ''), $item[$rateField] ?? null, $orientation);

                if (isset($cfg['date_field']) && ($date = $this->parseDate($item[$cfg['date_field']] ?? null, $cfg))) {
                    $dates[] = $date;
                }
            }
        } else {
            throw new RuntimeException("{$label}: mënyrë e panjohur '{$mode}'.");
        }

        return $this->finish($label, $rates, $dates, []);
    }

    private function perPair(array $source, array $cfg, array $codes, string $label): array
    {
        $codes = array_values(array_unique(array_filter(array_map('strtoupper', $codes), fn ($c) => $c !== '' && $c !== 'ALL')));

        if (! $codes) {
            throw new RuntimeException("{$label}: s'ka monedha aktive për t'u kërkuar.");
        }

        $orientation = (string) ($cfg['orientation'] ?? 'lek_per_unit');
        $rates = [];
        $dates = [];
        $errors = [];

        foreach ($codes as $code) {
            $url = str_replace(['{code}', '{source}'], $code, (string) $source['url']);

            try {
                $json = $this->getJson($url, null, $cfg);
                $this->addRate($rates, $code, Arr::get($json, (string) ($cfg['rate_path'] ?? '0.rate')), $orientation);

                if (! isset($rates[$code])) {
                    $errors[] = "{$code}: kurs i pavlefshëm";
                }

                if ($date = $this->parseDate(Arr::get($json, (string) ($cfg['time_path'] ?? '0.time')), $cfg)) {
                    $dates[] = $date;
                }
            } catch (Throwable $exception) {
                $errors[] = "{$code}: " . $exception->getMessage();
            }
        }

        if (! $rates) {
            throw new RuntimeException("{$label}: " . implode('; ', $errors));
        }

        return $this->finish($label, $rates, $dates, $errors);
    }

    /** @param array<string, array{rate: string, basis: string}> $rates */
    private function finish(string $label, array $rates, array $dates, array $errors): array
    {
        if (! $rates) {
            throw new RuntimeException("{$label}: s'u gjet asnjë kurs i vlefshëm në përgjigje.");
        }

        $date = $dates ? collect($dates)->sortBy(fn (Carbon $d) => $d->getTimestamp())->last() : Carbon::now(config('app.timezone'));

        foreach ($rates as $code => $item) {
            $rates[$code]['basis'] = $label . ' ' . $date->format('d.m.Y H:i');
        }

        return ['date' => $date, 'rates' => $rates, 'errors' => $errors];
    }

    /** @param array<string, array{rate: string, basis: string}> $rates */
    private function addRate(array &$rates, string $code, mixed $value, string $orientation): void
    {
        $code = strtoupper(trim($code));

        if (! preg_match('/^[A-Z]{3}$/', $code) || $code === 'ALL' || ! is_numeric($value) || (float) $value <= 0) {
            return;
        }

        $decimal = $this->decimal($value);
        $rates[$code] = [
            'rate' => $orientation === 'per_lek' ? bcdiv('1', $decimal, 10) : $decimal,
            'basis' => '',
        ];
    }

    private function parseDate(mixed $value, array $cfg): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $date = (($cfg['date_format'] ?? null) === 'unix' || (is_numeric($value) && (float) $value > 100000000))
                ? Carbon::createFromTimestamp((int) $value)
                : Carbon::parse((string) $value);

            return $date->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    private function at(array $json, string $path): mixed
    {
        return $path === '' ? $json : Arr::get($json, $path);
    }

    /** Lista e parë e objekteve që kanë fushat base dhe rate (deri 3 nivele). */
    private function findList(array $data, string $baseField, string $rateField, int $depth = 0): ?array
    {
        $first = reset($data);

        if (is_array($first) && array_key_exists($baseField, $first) && array_key_exists($rateField, $first)) {
            return $data;
        }

        if ($depth >= 3) {
            return null;
        }

        foreach ($data as $value) {
            if (is_array($value) && ($found = $this->findList($value, $baseField, $rateField, $depth + 1)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @throws RuntimeException */
    private function getJson(string $url, ?string $fallback, array $cfg): array
    {
        $last = 'gabim i panjohur';

        foreach (array_values(array_filter([$url, $fallback])) as $candidate) {
            try {
                $response = $this->http($cfg)->get($candidate);

                if ($response->status() === 429) {
                    sleep(min(5, max(1, (int) $response->header('Retry-After'))));
                    $response = $this->http($cfg)->get($candidate);
                }

                if (! $response->successful()) {
                    throw new RuntimeException('HTTP ' . $response->status());
                }

                $json = $response->json();

                if (! is_array($json)) {
                    throw new RuntimeException('përgjigje jo-JSON');
                }

                return $json;
            } catch (Throwable $exception) {
                $last = $exception->getMessage() . ' (' . (parse_url($candidate, PHP_URL_HOST) ?: 'url') . ')';
            }
        }

        throw new RuntimeException($last);
    }

    private function http(array $cfg)
    {
        $http = Http::timeout((int) ($cfg['timeout'] ?? 20))
            ->retry(2, (int) config('plugins.emiron-fx.general.source_retry_sleep_ms', 1500), throw: false)
            ->acceptJson()
            ->withHeaders(['User-Agent' => 'EmironFX/0.8 (+rate sync; exchange office)'] + (array) ($cfg['headers'] ?? []));

        $auth = (array) ($cfg['auth'] ?? []);

        if (($auth['type'] ?? null) === 'bearer') {
            $token = (string) ($cfg['__secret'] ?? '');

            if ($token === '') {
                throw new RuntimeException('Token-i mungon (vendose te FX · Kurset → Burimet; ruhet i enkriptuar).');
            }

            $http = $http->withToken($token);
        }

        return $http;
    }

    /** 1.0E-5 → "0.00001" (bcmath s'pranon notacion shkencor). */
    private function decimal(mixed $value): string
    {
        return rtrim(rtrim(sprintf('%.12F', (float) $value), '0'), '.') ?: '0';
    }
}
