<?php

namespace Botble\EmironFx\Http\Controllers;

use Botble\Base\Http\Controllers\BaseController;
use Botble\EmironFx\Models\FxAccessLog;
use Botble\EmironFx\Models\FxPartnerOperation;
use Botble\EmironFx\Models\FxTillMovement;
use Botble\EmironFx\Models\FxTillSession;
use Botble\EmironFx\Models\FxTransaction;
use Botble\EmironFx\Services\FxHoldingService;
use Botble\EmironFx\Services\FxPartnerReportService;
use Botble\EmironFx\Services\FxReconService;
use Botble\EmironFx\Services\FxTillService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Qarku i mbyllur, raporti i partnerëve dhe paneli personal. */
class FxFinanceController extends BaseController
{
    public function recon(FxReconService $recon)
    {
        abort_unless(fx_can('fx.bank.index'), 403);

        page_title()->setTitle(trans('plugins/emiron-fx::fx.recon.title'));

        return view('plugins/emiron-fx::finance.recon', ['r' => $recon->build(), 'canManage' => fx_can('fx.bank.manage')]);
    }

    public function partnerReport(Request $request, FxPartnerReportService $report)
    {
        abort_unless(fx_can('fx.reports.index'), 403);

        $date = $request->query('date') ? Carbon::parse($request->query('date')) : now();
        page_title()->setTitle(trans('plugins/emiron-fx::fx.partner_report.title'));

        if ($request->query('format') === 'csv') {
            FxAccessLog::record('export', 'partner_report', null, $date->toDateString());
            $csv = $report->csv($date);

            return response()->streamDownload(fn () => print($csv), 'Partneret_' . $date->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return view('plugins/emiron-fx::finance.partner-report', ['date' => $date, 'partners' => $report->build($date)]);
    }

    /** Paneli personal i arkëtarit: arka ime, veprimet e mia sot, diferencat e mia. */
    public function mine(FxTillService $tills, FxHoldingService $holding)
    {
        abort_unless(fx_can('fx.counter.use'), 403);

        page_title()->setTitle(trans('plugins/emiron-fx::fx.my.title'));
        $userId = (int) auth()->id();
        $till = $tills->tillFor($userId);
        $session = $till ? $tills->openSession($till) : null;

        $sessions = FxTillSession::query()->where('user_id', $userId)->whereNotNull('closed_at')
            ->where('closed_at', '>=', now()->subDays(30))->orderByDesc('id')->get();

        $variances = FxTillMovement::query()->whereIn('till_session_id', $sessions->pluck('id')->all() ?: [0])
            ->where('type', 'closing_variance')->get()->groupBy('till_session_id');

        return view('plugins/emiron-fx::finance.mine', [
            'till' => $till,
            'session' => $session,
            'balances' => $till ? $holding->balances($till->id) : [],
            'fx' => FxTransaction::query()->where('user_id', $userId)->whereDate('business_date', now()->toDateString())->with('voidedBy')->orderByDesc('id')->get(),
            'partnerOps' => FxPartnerOperation::query()->where('user_id', $userId)->whereDate('business_date', now()->toDateString())->with(['service', 'voidedBy'])->orderByDesc('id')->get(),
            'sessions' => $sessions,
            'variances' => $variances,
        ]);
    }
}
