<?php

namespace Botble\EmironFx\Http\Controllers;

use Botble\Base\Http\Controllers\BaseController;
use Botble\EmironFx\Services\FxDashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Paneli i ditës. 0.7.1: çdo bllok shfaqet vetëm me lejen e vet (fail-closed):
 *   fx.pnl.index      — fitimi/humbja dhe spread-i
 *   fx.sessions.index — arkat e të gjithëve dhe kufiri i gjendjes (pa të: vetëm arka ime)
 *   fx.bank.index     — likuiditeti (arkat + banka) dhe gjendjet e partnerëve
 */
class FxDashboardController extends BaseController
{
    public function index(Request $request, FxDashboardService $dashboard)
    {
        abort_unless(fx_can('fx.dashboard.index'), 403);

        page_title()->setTitle(trans('plugins/emiron-fx::fx.dashboard.title'));
        $date = $request->query('date') ? Carbon::parse($request->query('date')) : now();

        $canPnl = fx_can('fx.pnl.index');
        $canTills = fx_can('fx.sessions.index');
        $canBank = fx_can('fx.bank.index');

        $d = $dashboard->build($date);

        if (! $canTills) {
            $userId = (int) auth()->id();
            $d['tills'] = $d['tills']->filter(fn ($t) => (int) $t['till']->user_id === $userId)->values();
        }

        $pnlDay = $pnlMonth = null;

        if ($canPnl) {
            $pnl = app(\Botble\EmironFx\Services\FxPnlService::class);
            $pnlDay = $pnl->compute($date, $date);
            $pnlMonth = $pnl->compute($date->copy()->startOfMonth(), $date);
        }

        $partners = collect();
        $liquidity = [];

        if ($canBank) {
            $ops = app(\Botble\EmironFx\Services\FxPartnerOpsService::class);
            $bank = app(\Botble\EmironFx\Services\FxBankService::class);
            $holding = app(\Botble\EmironFx\Services\FxHoldingService::class);

            // Likuiditeti për monedhë: arkat + llogaritë bankare.
            foreach ($holding->balances() as $code => $amount) {
                $liquidity[$code]['tills'] = $amount;
            }

            $accounts = \Botble\EmironFx\Models\FxBankAccount::query()->where('is_active', true)->get();

            foreach ($accounts as $account) {
                $liquidity[$account->currency_code]['bank'] = \Botble\EmironFx\Services\FxMoney::add($liquidity[$account->currency_code]['bank'] ?? '0', $bank->balance($account));
            }

            ksort($liquidity);

            $partners = \Botble\EmironFx\Models\FxPartner::query()->where('is_active', true)->get()->map(fn ($p) => ['partner' => $p, 'balances' => $ops->balances($p->id)]);
        }

        return view('plugins/emiron-fx::dashboard.index', [
            'd' => $d,
            'canAml' => fx_can('fx.aml.manage'),
            'canPnl' => $canPnl,
            'canTills' => $canTills,
            'canBank' => $canBank,
            'pnlDay' => $pnlDay,
            'pnlMonth' => $pnlMonth,
            'partners' => $partners,
            'liquidity' => $liquidity,
        ]);
    }
}
