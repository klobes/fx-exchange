<?php

namespace Botble\EmironFx\Http\Controllers;

use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Http\Responses\BaseHttpResponse;
use Botble\EmironFx\Models\FxCurrency;
use Botble\EmironFx\Models\FxTillSession;
use Botble\EmironFx\Services\FxHoldingService;
use Botble\EmironFx\Services\FxMoney;
use Botble\EmironFx\Services\FxTillService;
use Botble\EmironFx\Tables\FxTillSessionTable;

/** Seancat e arkave: pamja e supervizorit dhe miratimi i mbylljes. */
class FxSessionController extends BaseController
{
    public function __construct(
        private readonly FxTillService $tills,
        private readonly FxHoldingService $holding,
    ) {
        $this->breadcrumb()
            ->add(trans('plugins/emiron-fx::fx.menu.root'), route('fx.rates.index'))
            ->add(trans('plugins/emiron-fx::fx.sessions.title'), route('fx.sessions.index'));
    }

    public function index(FxTillSessionTable $table)
    {
        abort_unless(fx_can('fx.sessions.index'), 403);

        if (request()->ajax()) {
            return $table->ajax();
        }

        page_title()->setTitle(trans('plugins/emiron-fx::fx.sessions.title'));

        return $table->renderTable();
    }

    /** Arkat me seancë të hapur: gjendja aktuale për monedhë (vetëm lexim, për supervizorin/adminin). */
    public function live()
    {
        abort_unless(fx_can('fx.sessions.index'), 403);

        page_title()->setTitle(trans('plugins/emiron-fx::fx.sessions.live_title'));

        $sessions = FxTillSession::query()
            ->where('status', FxTillSession::STATUS_OPEN)
            ->with(['till.office', 'cashier'])
            ->orderBy('till_id')
            ->get();

        $codes = ['ALL'];

        foreach (FxCurrency::query()->active()->ordered()->pluck('code') as $code) {
            $codes[] = $code;
        }

        $rows = [];
        $totals = [];
        $rates = $this->holding->valuationRates();

        foreach ($sessions as $session) {
            $balances = $this->holding->balances((int) $session->till_id);

            foreach ($balances as $code => $amount) {
                if (! FxMoney::isZero($amount) && ! in_array($code, $codes, true)) {
                    $codes[] = $code;
                }

                $totals[$code] = FxMoney::add($totals[$code] ?? '0', (string) $amount);
            }

            $rows[] = [
                'session' => $session,
                'balances' => $balances,
                'value' => FxMoney::round($this->holding->valueLek($balances, $rates), 0),
            ];
        }

        return view('plugins/emiron-fx::sessions.live', [
            'rows' => $rows,
            'codes' => $codes,
            'totals' => $totals,
            'totalValue' => FxMoney::round($this->holding->valueLek($totals, $rates), 0),
        ]);
    }

    public function show(FxTillSession $fxSession)
    {
        abort_unless(fx_can('fx.sessions.index'), 403);

        page_title()->setTitle(trans('plugins/emiron-fx::fx.sessions.show', ['id' => $fxSession->id]));

        $movements = $fxSession->movements()->with('author')->orderBy('id')->get();

        return view('plugins/emiron-fx::sessions.show', [
            'session' => $fxSession->load(['till.office', 'cashier', 'approver']),
            'movements' => $movements,
            'summary' => $movements->groupBy('currency_code')->map(fn ($rows) => $rows->groupBy('type')->map(fn ($r) => $r->sum(fn ($m) => (float) $m->amount))),
            'txCount' => $fxSession->transactions()->whereNull('voids_transaction_id')->count(),
            'voidCount' => $fxSession->transactions()->whereNotNull('voids_transaction_id')->count(),
            'canApprove' => fx_can('fx.sessions.approve'),
            'liveBalances' => $fxSession->status === FxTillSession::STATUS_OPEN ? $this->holding->balances((int) $fxSession->till_id) : null,
        ]);
    }

    public function approve(FxTillSession $fxSession, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.sessions.approve'), 403);

        $this->tills->approve($fxSession, (int) auth()->id());

        return $response->setNextUrl(route('fx.sessions.show', $fxSession->id))->setMessage(trans('plugins/emiron-fx::fx.sessions.approved'));
    }
}
