<?php

namespace Botble\EmironFx\Http\Controllers;

use Botble\Base\Http\Controllers\BaseController;
use Botble\EmironFx\Tables\FxTransactionTable;

class FxTransactionController extends BaseController
{
    public function aif(FxTransactionTable $table)
    {
        return $this->index($table->setFlag('aif_cash'), true);
    }

    public function index(FxTransactionTable $table, bool $aif = false)
    {
        abort_unless(fx_can('fx.transactions.index'), 403);

        $this->breadcrumb()
            ->add(trans('plugins/emiron-fx::fx.menu.root'), route('fx.rates.index'))
            ->add(trans('plugins/emiron-fx::fx.transactions.title'), route('fx.transactions.index'));

        if (request()->ajax()) {
            return $table->ajax();
        }

        page_title()->setTitle($aif
            ? trans('plugins/emiron-fx::fx.transactions.aif_title')
            : trans('plugins/emiron-fx::fx.transactions.title'));

        return $table->renderTable();
    }
}
