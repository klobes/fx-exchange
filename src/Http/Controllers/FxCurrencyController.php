<?php

namespace Botble\EmironFx\Http\Controllers;

use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Http\Responses\BaseHttpResponse;
use Botble\EmironFx\Forms\FxCurrencyForm;
use Botble\EmironFx\Http\Requests\FxCurrencyRequest;
use Botble\EmironFx\Models\FxCurrency;
use Botble\EmironFx\Services\FxCurrencyService;
use Botble\EmironFx\Tables\FxCurrencyTable;

class FxCurrencyController extends BaseController
{
    public function __construct(private readonly FxCurrencyService $currencies)
    {
        $this->breadcrumb()
            ->add(trans('plugins/emiron-fx::fx.menu.root'), route('fx.rates.index'))
            ->add(trans('plugins/emiron-fx::fx.currencies.title'), route('fx.currencies.index'));
    }

    public function index(FxCurrencyTable $table)
    {
        abort_unless(fx_can('fx.currencies.index'), 403);

        if (request()->ajax()) {
            return $table->ajax();
        }

        page_title()->setTitle(trans('plugins/emiron-fx::fx.currencies.title'));

        return $table->renderTable();
    }

    public function create()
    {
        abort_unless(fx_can('fx.currencies.create'), 403);

        page_title()->setTitle(trans('plugins/emiron-fx::fx.currencies.create'));

        return FxCurrencyForm::create()
            ->setUrl(route('fx.currencies.store'))
            ->renderForm();
    }

    public function store(FxCurrencyRequest $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.currencies.create'), 403);

        $currency = $this->currencies->create($request->validated(), $request);

        return $response
            ->setPreviousUrl(route('fx.currencies.index'))
            ->setNextUrl(route('fx.currencies.edit', $currency->id))
            ->setMessage(trans('core/base::notices.create_success_message'));
    }

    public function edit(FxCurrency $fxCurrency)
    {
        abort_unless(fx_can('fx.currencies.edit'), 403);

        page_title()->setTitle(trans('plugins/emiron-fx::fx.currencies.edit', ['code' => $fxCurrency->code]));

        return FxCurrencyForm::createFromModel($fxCurrency)
            ->setUrl(route('fx.currencies.update', $fxCurrency->id))
            ->renderForm();
    }

    public function update(FxCurrency $fxCurrency, FxCurrencyRequest $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.currencies.edit'), 403);

        $this->currencies->update($fxCurrency, $request->validated(), $request);

        return $response
            ->setPreviousUrl(route('fx.currencies.index'))
            ->setMessage(trans('core/base::notices.update_success_message'));
    }
}
