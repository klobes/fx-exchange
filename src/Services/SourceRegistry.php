<?php

namespace Botble\EmironFx\Services;

use Botble\EmironFx\Models\FxRateSource;
use Botble\EmironFx\Support\FxCrypto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Regjistri i burimeve të kurseve (0.8.1): një vend ku shihen të gjitha burimet.
 *
 *  boa_html          — BSH, faqja zyrtare (HTML), roli "zyrtar"; kursi që vlen për kontrollin e shmangies.
 *  erapi, ecb, fawaz — të ndërtuara në kod (aktivizohen me FX_REFERENCE_SOURCES), role "referencë".
 *  të tjerat (DB)    — burime JSON të konfigurueshme (fx_rate_sources), aktivizohen nga paneli.
 *
 * Roli "zyrtar" ndikon te kontrolli i shmangies; "referencë" dhe "pasqyrë e zyrtarit" vetëm krahasim/verifikim.
 */
class SourceRegistry
{
    public const OFFICIAL = 'boa_html';

    /** @var Collection<string, array<string, mixed>>|null */
    private ?Collection $cache = null;

    public function refresh(): void
    {
        $this->cache = null;
    }

    /** @return Collection<string, array<string, mixed>> kodi => përshkrimi */
    public function all(): Collection
    {
        if ($this->cache) {
            return $this->cache;
        }

        $envEnabled = (array) config('plugins.emiron-fx.general.reference_sources', []);
        $general = 'plugins.emiron-fx.general.';

        $items = [
            self::OFFICIAL => [
                'code' => self::OFFICIAL, 'name' => 'Banka e Shqipërisë (faqja zyrtare)', 'role' => 'official', 'driver' => 'boa',
                'url' => (string) config($general . 'boa_url'), 'fallback_url' => null, 'config' => [],
                'enabled' => (bool) config($general . 'boa_import_enabled', true), 'toggleable' => false,
                'attribution' => null, 'description' => 'HTML i faqes zyrtare; s\'ka API zyrtar. Kursi zyrtar që vlen për kontrollin e shmangies.',
                'times' => array_merge((array) config($general . 'boa_import_times', []), [config($general . 'boa_import_final_time')]),
            ],
        ];

        $builtin = [
            'erapi' => ['ExchangeRate-API', (string) config($general . 'reference_erapi_url'), 'Çdo monedhë kundrejt ALL; përditësim ditor.'],
            'ecb' => ['BQE (Frankfurter)', (string) config($general . 'reference_ecb_url'), 'Pa ALL: kryqëzohet me EUR-in e BSH-së.'],
            'fawaz' => ['Currency-API', (string) config($general . 'reference_fawaz_url'), 'CDN jsDelivr; përditësim ditor.'],
        ];

        foreach ($builtin as $code => [$name, $url, $description]) {
            $items[$code] = [
                'code' => $code, 'name' => $name, 'role' => 'reference', 'driver' => 'builtin', 'url' => $url, 'fallback_url' => null,
                'config' => [], 'enabled' => in_array($code, $envEnabled, true), 'toggleable' => false,
                'attribution' => $code === 'erapi' ? 'Rates By Exchange Rate API' : null, 'description' => $description,
                'times' => (array) config($general . 'reference_import_times', []),
            ];
        }

        try {
            if (Schema::hasTable('fx_rate_sources')) {
                foreach (FxRateSource::query()->orderBy('sort_order')->orderBy('id')->get() as $row) {
                    $items[$row->code] = [
                        'code' => $row->code, 'name' => $row->name, 'role' => $row->role, 'driver' => 'json', 'url' => $row->url,
                        'fallback_url' => $row->fallback_url, 'config' => (array) $row->config, 'enabled' => (bool) $row->enabled,
                        // 0.8.3: token-i ruhet i enkriptuar te kolona `secret`; këtu vetëm a kërkohet dhe a është vendosur (kurrë vlera).
                        'needs_secret' => (((array) $row->config)['auth']['type'] ?? null) === 'bearer',
                        'has_secret' => (string) $row->getRawOriginal('secret') !== '',
                        'toggleable' => true, 'attribution' => $row->attribution,
                        'description' => 'Burim JSON me URL (konfigurim, pa kod); mënyra: ' . (((array) $row->config)['mode'] ?? 'map'),
                        'times' => (array) config($general . 'reference_import_times', []),
                    ];
                }
            }
        } catch (Throwable) {
            // para migrimit: vetëm burimet e ndërtuara
        }

        // 0.8.2: cilat burime hyjnë në mesataren e rekomanduar (fx_source_settings; parazgjedhur vetëm Wise).
        $average = [];

        try {
            if (Schema::hasTable('fx_source_settings')) {
                $average = DB::table('fx_source_settings')->pluck('use_in_average', 'source_code')->all();
            }
        } catch (Throwable) {
            // para migrimit
        }

        foreach ($items as $code => $item) {
            $items[$code]['use_in_average'] = $item['role'] !== 'official' && ! empty($average[$code]);
        }

        return $this->cache = collect($items);
    }

    /** @return array<string, mixed>|null */
    public function find(string $code): ?array
    {
        return $this->all()->get($code);
    }

    /**
     * Token-i i dekriptuar i një burimi — vetëm për momentin e përdorimit (kërkesa HTTP). Kurrë s'ruhet në cache/log.
     * Kthen null nëse s'është vendosur; hedh përjashtim nëse s'dekriptohet (çelës i ndryshuar).
     */
    public function secret(string $code): ?string
    {
        $row = FxRateSource::query()->where('code', $code)->first();
        $payload = $row ? (string) $row->getRawOriginal('secret') : '';

        return $payload === '' ? null : FxCrypto::decrypt($payload);
    }

    public function label(string $code): string
    {
        return (string) ($this->find($code)['name'] ?? $code);
    }

    /** Kodet e burimeve të referencës/pasqyrës të aktivizuara (pa BSH-në zyrtare). @return array<int, string> */
    public function enabledReferenceCodes(): array
    {
        return $this->all()
            ->filter(fn (array $s) => $s['role'] !== 'official' && $s['enabled'])
            ->keys()
            ->values()
            ->all();
    }

    /** Burimet që shfaqen si kolona krahasimi (referencë + pasqyrë), të aktivizuara. @return array<string, string> kodi => emri */
    public function comparisonLabels(): array
    {
        return $this->all()
            ->filter(fn (array $s) => $s['role'] !== 'official' && $s['enabled'])
            ->map(fn (array $s) => $s['name'])
            ->all();
    }
}
