<?php

namespace Botble\EmironFx\Http\Controllers;

use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Http\Responses\BaseHttpResponse;
use Botble\EmironFx\Http\Requests\FxRatePublishRequest;
use Botble\EmironFx\Services\FxRateService;
use Botble\EmironFx\Services\ReferenceRateService;
use Botble\EmironFx\Tables\FxRateTable;

/** RULE_025/RULE_030: i hollë; leja kontrollohet si veprimi i parë; logjika te FxRateService. */
class FxRateController extends BaseController
{
    public function __construct(private readonly FxRateService $rates, private readonly ReferenceRateService $references)
    {
        $this->breadcrumb()->add(trans('plugins/emiron-fx::fx.menu.root'), route('fx.rates.index'));
    }

    public function index(FxRateTable $table)
    {
        abort_unless(fx_can('fx.rates.index'), 403);

        if (request()->ajax()) {
            return $table->ajax();
        }

        page_title()->setTitle(trans('plugins/emiron-fx::fx.rates.history'));

        return $table->renderTable();
    }

    /** 0.8.4: tabela e kurseve për sportel (A4, pa referencën e BSH-së). Hapet nga sporteli ose nga faqja e kurseve. */
    public function board()
    {
        abort_unless(fx_can('fx.rates.index') || fx_can('fx.counter.use'), 403);

        app()->setLocale('sq'); // tabela për klientin është gjithmonë në shqip

        $layout = in_array(request('layout'), ['portrait', 'landscape', 'tent'], true) ? request('layout') : 'tent';
        $board = $this->rates->publicBoard();
        $office = \Botble\EmironFx\Models\FxOffice::query()->where('is_active', true)->orderBy('id')->first();

        return view('plugins/emiron-fx::rates.board', [
            'rows' => $board['rows'],
            'updatedAt' => $board['updated_at'],
            'officeName' => $office?->legal_name ?: ($office?->name ?? config('app.name')),
            'officeInfo' => $office ? implode(' · ', array_filter([
                $office->nipt ? 'NIPT ' . $office->nipt : null,
                $office->address,
                $office->phone,
            ])) : '',
            'layout' => $layout,
        ]);
    }

    public function create()
    {
        abort_unless(fx_can('fx.rates.publish'), 403);

        page_title()->setTitle(trans('plugins/emiron-fx::fx.rates.publish'));
        $this->breadcrumb()->add(trans('plugins/emiron-fx::fx.rates.publish'), route('fx.rates.create'));

        $current = $this->rates->currentRates();
        $official = $this->rates->officialRates();
        $market = app(\Botble\EmironFx\Services\MarketRateService::class);
        $overview = collect($market->overview())->keyBy(fn (array $row) => $row['currency']->id);

        return view('plugins/emiron-fx::rates.publish', [
            // 0.8.2: MID i tregut + rekomandimi (vetëm sugjerim) dhe lëvizjet mbi prag për banner
            'market' => $overview,
            'marketDrift' => $overview->filter(fn (array $row) => $row['drift'] !== null && abs($row['drift']) >= $market->driftThreshold())->values(),
            'currencies' => $this->rates->activeCurrencies(),
            'current' => $current,
            'previous' => $this->rates->previousRates($current),
            'references' => $this->references->latest(),
            // 0.8.1: kolonat e krahasimit = burimet e referencës/pasqyrës të aktivizuara (ndërtuara + JSON nga paneli)
            'referenceLabels' => collect(app(\Botble\EmironFx\Services\SourceRegistry::class)->comparisonLabels()),
            'official' => $official,
            'suggestions' => $current->mapWithKeys(fn ($rate, $currencyId) => [
                $currencyId => $this->rates->suggestion($rate, $official->has($currencyId) ? (string) $official->get($currencyId)->rate : null),
            ]),
            'threshold' => (float) config('plugins.emiron-fx.general.deviation_threshold_percent', 3),
            'canOverride' => fx_can('fx.rates.override'),
        ]);
    }

    public function store(FxRatePublishRequest $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.rates.publish'), 403);

        $this->rates->publish(
            (array) $request->input('rows', []),
            $request->input('note'),
            $request->boolean('override'),
            fx_can('fx.rates.override'),
            auth()->id(),
            $request
        );

        return $response
            ->setPreviousUrl(route('fx.rates.index'))
            ->setNextUrl(route('fx.rates.index'))
            ->setMessage(trans('plugins/emiron-fx::fx.rates.published'));
    }
}
