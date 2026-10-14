<?php

namespace Botble\EmironFx\Http\Controllers;

use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Http\Responses\BaseHttpResponse;
use Botble\EmironFx\Forms\FxOfficeForm;
use Botble\EmironFx\Forms\FxTillForm;
use Botble\EmironFx\Http\Requests\FxOfficeRequest;
use Botble\EmironFx\Http\Requests\FxTillRequest;
use Botble\EmironFx\Models\FxOffice;
use Botble\EmironFx\Models\FxTill;
use Botble\EmironFx\Tables\FxOfficeTable;
use Botble\EmironFx\Tables\FxTillTable;

/** Zyrat dhe arkat (fx.offices.manage). Arkat s'fshihen: çaktivizohen. */
class FxOfficeController extends BaseController
{
    public function __construct()
    {
        $this->breadcrumb()->add(trans('plugins/emiron-fx::fx.menu.root'), route('fx.rates.index'));
    }

    public function index(FxOfficeTable $table)
    {
        abort_unless(fx_can('fx.offices.manage'), 403);

        return request()->ajax() ? $table->ajax() : tap($table, fn () => page_title()->setTitle(trans('plugins/emiron-fx::fx.offices.title')))->renderTable();
    }

    public function create()
    {
        abort_unless(fx_can('fx.offices.manage'), 403);
        page_title()->setTitle(trans('plugins/emiron-fx::fx.offices.create'));

        return FxOfficeForm::create()->setUrl(route('fx.offices.store'))->renderForm();
    }

    public function store(FxOfficeRequest $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.offices.manage'), 403);
        $office = FxOffice::query()->create($request->validated());

        return $response->setPreviousUrl(route('fx.offices.index'))->setNextUrl(route('fx.offices.edit', $office->id))
            ->setMessage(trans('core/base::notices.create_success_message'));
    }

    public function edit(FxOffice $fxOffice)
    {
        abort_unless(fx_can('fx.offices.manage'), 403);
        page_title()->setTitle($fxOffice->name);

        return FxOfficeForm::createFromModel($fxOffice)->setUrl(route('fx.offices.update', $fxOffice->id))->renderForm();
    }

    public function update(FxOffice $fxOffice, FxOfficeRequest $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.offices.manage'), 403);
        $fxOffice->fill($request->validated())->save();

        return $response->setPreviousUrl(route('fx.offices.index'))->setMessage(trans('core/base::notices.update_success_message'));
    }

    public function tills(FxTillTable $table)
    {
        abort_unless(fx_can('fx.offices.manage'), 403);

        return request()->ajax() ? $table->ajax() : tap($table, fn () => page_title()->setTitle(trans('plugins/emiron-fx::fx.tills.title')))->renderTable();
    }

    public function createTill()
    {
        abort_unless(fx_can('fx.offices.manage'), 403);
        page_title()->setTitle(trans('plugins/emiron-fx::fx.tills.create'));

        return FxTillForm::create()->setUrl(route('fx.tills.store'))->renderForm();
    }

    public function storeTill(FxTillRequest $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.offices.manage'), 403);
        $till = FxTill::query()->create($request->validated());

        return $response->setPreviousUrl(route('fx.tills.index'))->setNextUrl(route('fx.tills.edit', $till->id))
            ->setMessage(trans('core/base::notices.create_success_message'));
    }

    public function editTill(FxTill $fxTill)
    {
        abort_unless(fx_can('fx.offices.manage'), 403);
        page_title()->setTitle($fxTill->name);

        return FxTillForm::createFromModel($fxTill)->setUrl(route('fx.tills.update', $fxTill->id))->renderForm();
    }

    public function updateTill(FxTill $fxTill, FxTillRequest $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.offices.manage'), 403);
        $fxTill->fill($request->validated())->save();

        return $response->setPreviousUrl(route('fx.tills.index'))->setMessage(trans('core/base::notices.update_success_message'));
    }
}
