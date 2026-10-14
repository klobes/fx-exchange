<?php

namespace Botble\EmironFx\Http\Controllers;

use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Http\Responses\BaseHttpResponse;
use Botble\EmironFx\Models\FxAccessLog;
use Botble\EmironFx\Models\FxCurrency;
use Botble\EmironFx\Models\FxRateSource;
use Botble\EmironFx\Support\FxCrypto;
use Botble\EmironFx\Models\FxSourceRun;
use Botble\EmironFx\Services\MarketRateService;
use Botble\EmironFx\Services\SourceRegistry;
use Botble\EmironFx\Services\SourceSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * FX · Kurset → Burimet (0.8.1): një vend ku shihet çdo burim kursesh, a punon, çfarë dha, dhe ku provohet/importohet.
 *
 *  fx.sources.index  — shiko statusin, historinë dhe "Provo tani" (pa ruajtur)
 *  fx.sources.manage — "Importo tani" dhe aktivizo/çaktivizo burimet JSON
 */
class FxSourceController extends BaseController
{
    public function __construct(
        private readonly SourceRegistry $registry,
        private readonly SourceSyncService $sync,
    ) {
        $this->breadcrumb()
            ->add(trans('plugins/emiron-fx::fx.menu.root'), route('fx.rates.index'))
            ->add(trans('plugins/emiron-fx::fx.sources.title'), route('fx.sources.index'));
    }

    public function index()
    {
        abort_unless(fx_can('fx.sources.index'), 403);

        page_title()->setTitle(trans('plugins/emiron-fx::fx.sources.title'));

        $market = app(MarketRateService::class);

        return view('plugins/emiron-fx::sources.index', [
            'market' => $market->overview(),
            'marketSettings' => [
                'margin_mode' => $market->marginMode(),
                'drift_threshold' => $market->driftThreshold(),
                'min_sources' => $market->minSources(),
                'default_margins' => $market->defaultMargins(),
                'loss_guard_mode' => app(\Botble\EmironFx\Services\LossGuardService::class)->mode(),
                'margins' => $market->margins(),
            ],
            'sources' => $this->sync->overview(),
            'canManage' => fx_can('fx.sources.manage'),
            'result' => session('fx_source_result'),
        ]);
    }

    /** mode=dry (leje fx.sources.index) | mode=import (leje fx.sources.manage). */
    public function run(string $code, Request $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.sources.index'), 403);
        abort_unless($this->registry->find($code), 404);

        $dry = $request->input('mode') !== 'import';

        if (! $dry) {
            abort_unless(fx_can('fx.sources.manage'), 403);
        }

        $result = $this->sync->run($code, $dry, 'user', (int) auth()->id());

        session()->flash('fx_source_result', $result);

        $response->setPreviousUrl(route('fx.sources.index'))->setNextUrl(route('fx.sources.index'));

        if ($result['status'] === 'failed') {
            return $response->setError()->setMessage($result['label'] . ': ' . $result['error']);
        }

        return $response->setMessage(trans($dry ? 'plugins/emiron-fx::fx.sources.dry_done' : 'plugins/emiron-fx::fx.sources.import_done', [
            'source' => $result['label'],
            'read' => $result['read'],
            'saved' => $result['saved'],
            'unchanged' => $result['unchanged'],
            'skipped' => count($result['skipped']),
        ]));
    }

    public function toggle(string $code, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.sources.manage'), 403);

        $source = FxRateSource::query()->where('code', $code)->firstOrFail();
        $source->forceFill(['enabled' => ! $source->enabled])->save();
        $this->registry->refresh();

        return $response
            ->setPreviousUrl(route('fx.sources.index'))
            ->setNextUrl(route('fx.sources.index'))
            ->setMessage(trans($source->enabled ? 'plugins/emiron-fx::fx.sources.enabled_msg' : 'plugins/emiron-fx::fx.sources.disabled_msg', ['source' => $source->name]));
    }

    /**
     * Vendos/heq token-in e API-së të një burimi (0.8.3). Ruhet i ENKRIPTUAR (FxCrypto) te kolona `secret`;
     * kurrë s'kthehet në pamje, s'shkruhet në log dhe s'hyn në përgjigje. Vetëm për burimet që kërkojnë token.
     */
    public function secret(string $code, Request $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.sources.manage'), 403);

        $source = $this->registry->find($code);
        abort_if(! $source || $source['driver'] !== 'json' || empty($source['needs_secret']), 404);

        $row = FxRateSource::query()->where('code', $code)->firstOrFail();
        $response->setPreviousUrl(route('fx.sources.index'))->setNextUrl(route('fx.sources.index'));

        if ($request->boolean('clear')) {
            DB::table('fx_rate_sources')->where('id', $row->id)->update(['secret' => null, 'updated_at' => now()]);
            FxAccessLog::record('source_secret', 'rate_source', $row->id, 'token u hoq: ' . $code);
            $this->registry->refresh();

            return $response->setMessage(trans('plugins/emiron-fx::fx.sources.secret_cleared', ['source' => $source['name']]));
        }

        $data = $request->validate(['token' => ['required', 'string', 'min:8', 'max:500']]);

        DB::table('fx_rate_sources')->where('id', $row->id)->update([
            'secret' => FxCrypto::encrypt(trim($data['token'])),
            'updated_at' => now(),
        ]);
        FxAccessLog::record('source_secret', 'rate_source', $row->id, 'token u vendos: ' . $code);
        $this->registry->refresh();

        return $response->setMessage(trans('plugins/emiron-fx::fx.sources.secret_saved', ['source' => $source['name']]));
    }

    /** Shton/heq një burim nga "mesatarja e rekomanduar" (0.8.2). */
    public function average(string $code, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.sources.manage'), 403);

        $source = $this->registry->find($code);
        abort_if(! $source || $source['role'] === 'official', 404);

        $now = ! $source['use_in_average'];

        DB::table('fx_source_settings')->updateOrInsert(
            ['source_code' => $code],
            ['use_in_average' => $now, 'updated_at' => now(), 'created_at' => now()]
        );
        $this->registry->refresh();

        return $response
            ->setPreviousUrl(route('fx.sources.index'))
            ->setNextUrl(route('fx.sources.index'))
            ->setMessage(trans($now ? 'plugins/emiron-fx::fx.sources.avg_msg_on' : 'plugins/emiron-fx::fx.sources.avg_msg_off', ['source' => $source['name']]));
    }

    /** Cilësimet e rekomandimit: mënyra e marzhit, pragu, burimet minimale, marzhet për monedhë (0.8.2). */
    public function market(Request $request, MarketRateService $market, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.sources.manage'), 403);

        $data = $request->validate([
            'margin_mode' => ['required', Rule::in(MarketRateService::MODES)],
            'drift_threshold' => ['required', 'numeric', 'min:0', 'max:50'],
            'min_sources' => ['required', 'integer', 'min:1', 'max:10'],
            'loss_guard_mode' => ['nullable', Rule::in(\Botble\EmironFx\Services\LossGuardService::MODES)],
            'default_buy_lek' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'default_sell_lek' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'margins' => ['nullable', 'array'],
            'margins.*.buy_pct' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'margins.*.sell_pct' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'margins.*.buy_lek' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'margins.*.sell_lek' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        $market->putSetting('margin_mode', $data['margin_mode']);
        $market->putSetting('drift_threshold', (string) $data['drift_threshold']);
        $market->putSetting('min_sources', (string) $data['min_sources']);

        foreach (['default_buy_lek', 'default_sell_lek'] as $key) {
            if (array_key_exists($key, $data)) {
                $market->putSetting($key, $data[$key] === null ? null : (string) $data[$key]);
            }
        }

        if (! empty($data['loss_guard_mode'])) {
            $market->putSetting('loss_guard_mode', $data['loss_guard_mode']);
        }

        $currencyIds = FxCurrency::query()->pluck('id')->all();

        foreach ((array) ($data['margins'] ?? []) as $currencyId => $values) {
            if (! in_array((int) $currencyId, $currencyIds, true)) {
                continue;
            }

            $clean = fn ($v) => ($v === null || $v === '') ? null : (string) $v;

            DB::table('fx_market_margins')->updateOrInsert(['currency_id' => (int) $currencyId], [
                'buy_pct' => $clean($values['buy_pct'] ?? null),
                'sell_pct' => $clean($values['sell_pct'] ?? null),
                'buy_lek' => $clean($values['buy_lek'] ?? null),
                'sell_lek' => $clean($values['sell_lek'] ?? null),
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }

        return $response
            ->setPreviousUrl(route('fx.sources.index'))
            ->setNextUrl(route('fx.sources.index'))
            ->setMessage(trans('plugins/emiron-fx::fx.sources.settings_saved'));
    }

    public function history(string $code)
    {
        abort_unless(fx_can('fx.sources.index'), 403);

        $source = $this->registry->find($code);
        abort_unless($source, 404);

        page_title()->setTitle(trans('plugins/emiron-fx::fx.sources.history_title', ['source' => $source['name']]));
        $this->breadcrumb()->add($source['name'], route('fx.sources.history', $code));

        return view('plugins/emiron-fx::sources.history', [
            'source' => $source,
            'runs' => FxSourceRun::query()->where('source_code', $code)->orderByDesc('id')->limit(100)->get(),
        ]);
    }
}
