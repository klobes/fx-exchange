<?php

namespace Botble\EmironFx\Http\Controllers;

use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Http\Responses\BaseHttpResponse;
use Botble\EmironFx\Forms\FxCustomerForm;
use Botble\EmironFx\Http\Requests\FxCustomerRequest;
use Botble\EmironFx\Models\FxAccessLog;
use Botble\EmironFx\Models\FxCustomer;
use Botble\EmironFx\Services\FxCustomerService;
use Botble\EmironFx\Tables\FxCustomerTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Faza 2b — klientët e identifikuar. Çdo shikim/kërkim lë gjurmë (fx_access_logs). */
class FxCustomerController extends BaseController
{
    public function __construct(private readonly FxCustomerService $customers)
    {
        $this->breadcrumb()
            ->add(trans('plugins/emiron-fx::fx.menu.root'), route('fx.rates.index'))
            ->add(trans('plugins/emiron-fx::fx.customers.title'), route('fx.customers.index'));
    }

    public function index(FxCustomerTable $table)
    {
        abort_unless(fx_can('fx.customers.index'), 403);

        if (request()->ajax()) {
            return $table->ajax();
        }

        page_title()->setTitle(trans('plugins/emiron-fx::fx.customers.title'));

        return $table->renderTable();
    }

    /** Kërkimi nga sporteli: vetëm me lloj + numër dokumenti (HMAC), kthen të dhëna minimale. */
    public function search(Request $request): JsonResponse
    {
        abort_unless(fx_can('fx.counter.use') || fx_can('fx.customers.index'), 403);

        $customer = $this->customers->findByDocument((string) $request->query('doc_type'), (string) $request->query('doc_number'));

        if (! $customer) {
            return response()->json(['found' => false]);
        }

        return response()->json([
            'found' => true,
            'id' => $customer->id,
            'name' => $customer->displayName(),
            'category' => $customer->client_category,
            'doc' => $customer->maskedDoc(),
            'is_pep' => $customer->is_pep,
            'is_blocked' => $customer->is_blocked,
            'doc_expired' => $customer->docExpired(),
            'risk_level' => $customer->risk_level,
        ]);
    }

    public function create()
    {
        abort_unless(fx_can('fx.customers.edit'), 403);
        page_title()->setTitle(trans('plugins/emiron-fx::fx.customers.create'));

        return FxCustomerForm::create()->setUrl(route('fx.customers.store'))->renderForm();
    }

    public function store(FxCustomerRequest $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.customers.edit'), 403);

        $customer = $this->customers->findOrCreate($request->validated(), (int) auth()->id());

        return $response->setPreviousUrl(route('fx.customers.index'))->setNextUrl(route('fx.customers.edit', $customer->id))
            ->setMessage(trans('core/base::notices.create_success_message'));
    }

    public function edit(FxCustomer $fxCustomer)
    {
        abort_unless(fx_can('fx.customers.index'), 403);

        FxAccessLog::record('view', 'customer', $fxCustomer->id);
        page_title()->setTitle($fxCustomer->displayName());

        return FxCustomerForm::createFromModel($fxCustomer)->setUrl(route('fx.customers.update', $fxCustomer->id))->renderForm();
    }

    public function update(FxCustomer $fxCustomer, FxCustomerRequest $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.customers.edit'), 403);

        $this->customers->update($fxCustomer, $request->validated());

        return $response->setPreviousUrl(route('fx.customers.index'))->setMessage(trans('core/base::notices.update_success_message'));
    }
}
