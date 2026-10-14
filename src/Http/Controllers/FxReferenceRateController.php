<?php

namespace Botble\EmironFx\Http\Controllers;

use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Http\Responses\BaseHttpResponse;
use Botble\EmironFx\Services\FxRateService;
use Botble\EmironFx\Services\ReferenceRateService;
use Botble\EmironFx\Services\SourceRegistry;
use Botble\EmironFx\Services\SourceSyncService;
use Botble\EmironFx\Tables\FxReferenceRateTable;
use Throwable;

/** Referenca e tregut (jo zyrtare): krahasimi me BSH-në + importi manual. */
class FxReferenceRateController extends BaseController
{
    public function __construct(
        private readonly FxRateService $rates,
        private readonly ReferenceRateService $references,
    ) {
        $this->breadcrumb()->add(trans('plugins/emiron-fx::fx.menu.root'), route('fx.rates.index'));
    }

    public function index()
    {
        abort_unless(fx_can('fx.reference.index'), 403);

        page_title()->setTitle(trans('plugins/emiron-fx::fx.reference.title'));
        $this->breadcrumb()->add(trans('plugins/emiron-fx::fx.reference.title'), route('fx.reference.index'));

        return view('plugins/emiron-fx::reference.index', [
            'currencies' => $this->rates->activeCurrencies(),
            'official' => $this->rates->officialRates(),
            'latest' => $this->references->latest(),
            'sourceInfo' => $this->references->sourceInfo(),
            'sources' => collect(app(SourceRegistry::class)->comparisonLabels()),
            'limit' => (float) config('plugins.emiron-fx.general.reference_divergence_alert_percent', 1.5),
            'times' => (array) config('plugins.emiron-fx.general.reference_import_times', []),
            'canImport' => fx_can('fx.reference.import'),
            'rateService' => $this->rates,
        ]);
    }

    public function history(FxReferenceRateTable $table)
    {
        abort_unless(fx_can('fx.reference.index'), 403);

        if (request()->ajax()) {
            return $table->ajax();
        }

        page_title()->setTitle(trans('plugins/emiron-fx::fx.reference.history'));
        $this->breadcrumb()
            ->add(trans('plugins/emiron-fx::fx.reference.title'), route('fx.reference.index'))
            ->add(trans('plugins/emiron-fx::fx.reference.history'), route('fx.reference.history'));

        return $table->renderTable();
    }

    public function import(BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.reference.import'), 403);

        $messages = [];
        $failed = [];

        $sync = app(SourceSyncService::class);

        foreach (app(SourceRegistry::class)->enabledReferenceCodes() as $source) {
            // 0.8.1: çdo përpjekje regjistrohet (FX · Kurset → Burimet) dhe kurset e papërputhshme me BSH-në hidhen.
            $result = $sync->run($source, false, 'user', auth()->id());

            if ($result['status'] === 'failed') {
                $failed[] = $result['label'] . ': ' . $result['error'];

                continue;
            }

            $messages[] = trans('plugins/emiron-fx::fx.reference.imported_source', [
                'source' => $result['label'],
                'date' => $result['data_date'] ?? '—',
                'saved' => $result['saved'],
                'unchanged' => $result['unchanged'],
            ]);
        }

        $response->setPreviousUrl(route('fx.reference.index'))->setNextUrl(route('fx.reference.index'));

        if ($failed && ! $messages) {
            return $response->setError()->setMessage(implode(' | ', $failed));
        }

        return $response->setMessage(implode(' | ', array_merge($messages, $failed)));
    }
}
