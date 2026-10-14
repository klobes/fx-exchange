<?php

namespace Botble\EmironFx\Http\Controllers;

use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Http\Responses\BaseHttpResponse;
use Botble\EmironFx\Http\Requests\FxOfficialRateRequest;
use Botble\EmironFx\Services\BoaRateFetcher;
use Botble\EmironFx\Services\FxAlertService;
use Botble\EmironFx\Services\FxRateService;
use Botble\EmironFx\Services\SourceRegistry;
use Botble\EmironFx\Services\SourceSyncService;
use Throwable;
use Botble\EmironFx\Tables\FxOfficialRateTable;
use Illuminate\Support\Carbon;

class FxOfficialRateController extends BaseController
{
    public function __construct(private readonly FxRateService $rates)
    {
        $this->breadcrumb()->add(trans('plugins/emiron-fx::fx.menu.root'), route('fx.rates.index'));
    }

    public function index(FxOfficialRateTable $table)
    {
        abort_unless(fx_can('fx.official-rates.index'), 403);

        if (request()->ajax()) {
            return $table->ajax();
        }

        page_title()->setTitle(trans('plugins/emiron-fx::fx.official.title'));

        return $table->renderTable();
    }

    public function create()
    {
        abort_unless(fx_can('fx.official-rates.create'), 403);

        page_title()->setTitle(trans('plugins/emiron-fx::fx.official.create'));
        $this->breadcrumb()->add(trans('plugins/emiron-fx::fx.official.create'), route('fx.official-rates.create'));

        return view('plugins/emiron-fx::official.create', [
            'currencies' => $this->rates->activeCurrencies(),
            'latest' => $this->rates->officialRates(),
        ]);
    }

    public function store(FxOfficialRateRequest $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.official-rates.create'), 403);

        $count = $this->rates->recordOfficial(
            Carbon::createFromFormat('Y-m-d', $request->input('rate_date')),
            (array) $request->input('rates', []),
            $request->input('note'),
            auth()->id(),
            $request
        );

        return $response
            ->setPreviousUrl(route('fx.official-rates.index'))
            ->setNextUrl(route('fx.official-rates.index'))
            ->setMessage(trans('plugins/emiron-fx::fx.official.saved', ['count' => $count]));
    }

    /** Importi manual "tani" nga faqja e BSH-së (i njëjti zinxhir si cron-i). */
    public function import(BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.official-rates.create'), 403);

        // 0.8.1: i njëjti zinxhir si cron-i, por me regjistër përpjekjesh (FX · Kurset → Burimet).
        $result = app(SourceSyncService::class)->run(SourceRegistry::OFFICIAL, false, 'user', auth()->id());

        if ($result['status'] === 'failed') {
            return $response
                ->setError()
                ->setPreviousUrl(route('fx.official-rates.create'))
                ->setMessage(trans('plugins/emiron-fx::fx.official.import_failed', ['error' => (string) $result['error']]));
        }

        return $response
            ->setPreviousUrl(route('fx.official-rates.index'))
            ->setNextUrl(route('fx.official-rates.index'))
            ->setMessage(trans('plugins/emiron-fx::fx.official.imported', [
                'date' => $result['data_date'] ?? '—',
                'saved' => $result['saved'],
                'unchanged' => $result['unchanged'],
                'skipped' => count($result['skipped']),
            ]));
    }
}
