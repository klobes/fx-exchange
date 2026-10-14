<?php

namespace Botble\EmironFx\Tables;

use Botble\Base\Facades\BaseHelper;
use Botble\Base\Facades\Html;
use Botble\EmironFx\Models\FxAmlCase;
use Botble\Table\Abstracts\TableAbstract;
use Botble\Table\Columns\Column;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;

class FxAmlCaseTable extends TableAbstract
{
    protected $hasActions = false;

    protected $hasOperations = false;

    protected $hasCheckbox = false;

    public function setup(): void
    {
        $this->model(FxAmlCase::class);
    }

    public function ajax(): JsonResponse
    {
        return $this->toJson($this->table->eloquent($this->query())
            ->editColumn('id', fn (FxAmlCase $c) => Html::link(route('fx.aml.show', $c->id), '#' . $c->id))
            ->editColumn('customer_id', fn (FxAmlCase $c) => BaseHelper::clean($c->customer?->displayName() ?? '—'))
            ->editColumn('status', fn (FxAmlCase $c) => BaseHelper::renderBadge(
                trans('plugins/emiron-fx::fx.aml.status_' . $c->status),
                ['open' => 'danger', 'reported' => 'warning', 'closed' => 'success'][$c->status] ?? 'secondary'
            ))
            ->editColumn('opened_by', fn (FxAmlCase $c) => BaseHelper::clean($c->opener?->name ?? '—'))
            ->editColumn('created_at', fn (FxAmlCase $c) => BaseHelper::formatDateTime($c->created_at))
            ->escapeColumns([]));
    }

    public function query(): Relation|Builder|QueryBuilder
    {
        return $this->applyScopes($this->getModel()->query()->with(['customer', 'opener'])->orderByRaw("status = 'open' desc")->orderByDesc('id'));
    }

    public function columns(): array
    {
        $t = fn ($k) => trans('plugins/emiron-fx::fx.aml.' . $k);

        return [
            Column::make('id')->title('#')->searchable(false),
            Column::make('customer_id')->title($t('customer'))->searchable(false)->orderable(false),
            Column::make('status')->title($t('status'))->searchable(false),
            Column::make('opened_by')->title($t('opened_by'))->searchable(false)->orderable(false),
            Column::make('created_at')->title($t('created_at'))->searchable(false),
        ];
    }
}
