<?php

namespace Botble\EmironFx\Http\Controllers;

use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Http\Responses\BaseHttpResponse;
use Botble\EmironFx\Http\Requests\FxAmlRequest;
use Botble\EmironFx\Http\Requests\FxAmlUpdateRequest;
use Botble\EmironFx\Models\FxAccessLog;
use Botble\EmironFx\Models\FxAmlCase;
use Botble\EmironFx\Models\FxCustomer;
use Botble\EmironFx\Services\FxCustomerService;
use Botble\EmironFx\Tables\FxAmlCaseTable;
use Illuminate\Support\Facades\DB;

/**
 * Rastet e dyshimta. Çdo përdorues i sportelit mund të HAPË një rast
 * (dhe klienti pezullohet menjëherë); vetëm compliance i sheh dhe i menaxhon.
 * Arkëtari s'sheh listën — parandalim i paralajmërimit të klientit.
 */
class FxAmlController extends BaseController
{
    public function __construct(private readonly FxCustomerService $customers)
    {
        $this->breadcrumb()
            ->add(trans('plugins/emiron-fx::fx.menu.root'), route('fx.rates.index'))
            ->add(trans('plugins/emiron-fx::fx.aml.title'), route('fx.aml.index'));
    }

    public function index(FxAmlCaseTable $table)
    {
        abort_unless(fx_can('fx.aml.manage'), 403);

        if (request()->ajax()) {
            return $table->ajax();
        }

        page_title()->setTitle(trans('plugins/emiron-fx::fx.aml.title'));

        return $table->renderTable();
    }

    public function store(FxAmlRequest $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.counter.use') || fx_can('fx.aml.manage'), 403);

        DB::transaction(function () use ($request): void {
            $case = FxAmlCase::query()->create([
                'customer_id' => $request->input('customer_id'),
                'transaction_id' => $request->input('transaction_id'),
                'status' => 'open',
                'description' => (string) $request->input('description'),
                'opened_by' => (int) auth()->id(),
            ]);

            if ($customer = FxCustomer::query()->find($request->input('customer_id'))) {
                $this->customers->setBlocked($customer, true, 'AML #' . $case->id);
            }

            FxAccessLog::record('aml_open', 'aml_case', $case->id);
        });

        return $response->setNextUrl(fx_can('fx.aml.manage') ? route('fx.aml.index') : route('fx.counter.index'))
            ->setMessage(trans('plugins/emiron-fx::fx.aml.opened'));
    }

    public function show(FxAmlCase $fxAmlCase)
    {
        abort_unless(fx_can('fx.aml.manage'), 403);

        FxAccessLog::record('view', 'aml_case', $fxAmlCase->id);
        page_title()->setTitle(trans('plugins/emiron-fx::fx.aml.show', ['id' => $fxAmlCase->id]));

        return view('plugins/emiron-fx::aml.show', ['case' => $fxAmlCase->load(['customer', 'transaction', 'opener'])]);
    }

    public function update(FxAmlCase $fxAmlCase, FxAmlUpdateRequest $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.aml.manage'), 403);

        $status = (string) $request->input('status');

        $fxAmlCase->fill([
            'status' => $status,
            'decision' => $request->input('decision'),
            'fiu_reference' => $request->input('fiu_reference'),
            'reported_at' => $status === 'reported' ? ($fxAmlCase->reported_at ?? now()) : $fxAmlCase->reported_at,
            'closed_by' => $status === 'closed' ? (int) auth()->id() : null,
            'closed_at' => $status === 'closed' ? now() : null,
        ])->save();

        if ($request->boolean('unblock') && $fxAmlCase->customer) {
            $this->customers->setBlocked($fxAmlCase->customer, false, 'AML #' . $fxAmlCase->id . ' ' . $status);
        }

        FxAccessLog::record('aml_update', 'aml_case', $fxAmlCase->id, $status);

        return $response->setNextUrl(route('fx.aml.show', $fxAmlCase->id))->setMessage(trans('core/base::notices.update_success_message'));
    }
}
