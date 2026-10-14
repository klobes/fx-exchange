<?php

namespace Botble\EmironFx\Http\Controllers;

use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Http\Responses\BaseHttpResponse;
use Botble\EmironFx\Models\FxPartnerOperation;
use Botble\EmironFx\Services\FxAlertService;
use Botble\EmironFx\Services\FxPartnerOpsService;
use Botble\EmironFx\Services\FxTillService;
use Illuminate\Http\Request;

/** Veprimet me partnerët nga sporteli (në arkën e hapur të arkëtarit). */
class FxPartnerOpController extends BaseController
{
    public function __construct(
        private readonly FxPartnerOpsService $ops,
        private readonly FxTillService $tills,
    ) {
    }

    public function store(Request $request, BaseHttpResponse $response, FxAlertService $alerts)
    {
        abort_unless(fx_can('fx.counter.use'), 403);

        $data = $request->validate([
            'service_id' => ['required', 'integer'],
            'currency_code' => ['required', 'regex:/^[A-Z]{3}$/'],
            'amount' => ['required', 'regex:/^\d{1,12}([.,]\d{1,2})?$/'],
            'reference' => ['nullable', 'string', 'max:80'],
        ]);

        $till = $this->tills->tillFor((int) auth()->id());
        $session = $till ? $this->tills->openSession($till) : null;
        abort_unless($session, 403);

        $op = $this->ops->execute($session, (int) auth()->id(), $data);

        foreach ($this->ops->lowPrepaid() as $low) {
            $alerts->prepaidLow($low['partner'], $low['balance']);
        }

        if ($request->expectsJson()) {
            return response()->json(['error' => false, 'message' => trans('plugins/emiron-fx::fx.partners.saved', ['number' => $op->number])]);
        }

        return $response->setNextUrl(route('fx.counter.index') . '#partners')
            ->setMessage(trans('plugins/emiron-fx::fx.partners.saved', ['number' => $op->number]));
    }

    public function void(FxPartnerOperation $fxPartnerOp, Request $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.counter.void'), 403);

        $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $reversal = $this->ops->void($fxPartnerOp, (int) auth()->id(), (string) $request->input('reason'));

        if ($request->expectsJson()) {
            return response()->json(['error' => false, 'message' => trans('plugins/emiron-fx::fx.counter.voided', ['number' => $fxPartnerOp->number, 'reversal' => $reversal->number])]);
        }

        return $response->setNextUrl(route('fx.counter.index') . '#partners')
            ->setMessage(trans('plugins/emiron-fx::fx.counter.voided', ['number' => $fxPartnerOp->number, 'reversal' => $reversal->number]));
    }
}
