<?php

namespace Botble\EmironFx\Http\Controllers;

use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Http\Responses\BaseHttpResponse;
use Botble\EmironFx\Http\Requests\FxMovementRequest;
use Botble\EmironFx\Http\Requests\FxTillCountRequest;
use Botble\EmironFx\Http\Requests\FxTransactionRequest;
use Botble\EmironFx\Http\Requests\FxVoidRequest;
use Botble\EmironFx\Models\FxTransaction;
use Botble\EmironFx\Services\FxCounterService;
use Botble\EmironFx\Services\FxHoldingService;
use Botble\EmironFx\Services\FxRateService;
use Botble\EmironFx\Services\FxTillService;

/** Sporteli: ekrani i arkëtarit. RULE_025/030: i hollë, leja e para, logjika te shërbimet. */
class FxCounterController extends BaseController
{
    public function __construct(
        private readonly FxTillService $tills,
        private readonly FxCounterService $counter,
        private readonly FxHoldingService $holding,
        private readonly FxRateService $rates,
    ) {
        $this->breadcrumb()->add(trans('plugins/emiron-fx::fx.menu.root'), route('fx.rates.index'));
    }

    public function index()
    {
        abort_unless(fx_can('fx.counter.use'), 403);

        page_title()->setTitle(trans('plugins/emiron-fx::fx.counter.title'));
        $till = $this->tills->tillFor((int) auth()->id());

        if (! $till) {
            return view('plugins/emiron-fx::counter.no-till');
        }

        $session = $this->tills->openSession($till);
        $balances = $this->holding->balances($till->id);
        $codes = $this->tills->currencyCodes($till);

        if (! $session) {
            return view('plugins/emiron-fx::counter.open', compact('till', 'balances', 'codes'));
        }

        $currencies = $this->rates->activeCurrencies();
        $current = $this->rates->currentRates();

        return view('plugins/emiron-fx::counter.index', [
            'till' => $till,
            'session' => $session,
            'balances' => $balances,
            'codes' => $codes,
            'currencies' => $currencies,
            'current' => $current,
            'staleCodes' => $currencies->filter(fn ($c) => $current->has($c->id) && $this->rates->isStale($current->get($c->id)))->pluck('code')->values()->all(),
            'holding' => $this->holding->check(),
            // 0.8.0: një kryqëzim shfaqet si një rresht (këmbisa 2 fshihet; amounts e saj merren nga pairLeg)
            'transactions' => $session->transactions()->notSecondLeg()
                ->with(FxTransaction::supportsCross() ? ['voidedBy', 'pairLeg'] : ['voidedBy'])
                ->latest('id')->limit(50)->get(),
            'crossEnabled' => (bool) config('plugins.emiron-fx.general.cross_enabled', false),
            'categories' => (array) config('plugins.emiron-fx.general.client_categories', []),
            'idThreshold' => (string) config('plugins.emiron-fx.general.id_threshold_lek', '100000'),
            'docTypes' => (array) config('plugins.emiron-fx.general.id_document_types', []),
            'lekDecimals' => (int) config('plugins.emiron-fx.general.lek_decimals', 0),
            'canVoid' => fx_can('fx.counter.void'),
            'services' => app(\Botble\EmironFx\Services\FxPartnerOpsService::class)->activeServices(),
            'partnerOps' => \Botble\EmironFx\Models\FxPartnerOperation::query()->where('till_session_id', $session->id)->with(['service', 'voidedBy'])->latest('id')->limit(50)->get(),
            'canMove' => fx_can('fx.counter.movement'),
            'prepaid' => app(\Botble\EmironFx\Services\FxPartnerOpsService::class)->prepaidOverview(),
            // 0.8.4: mbrojtja nga humbja në shitje
            'lossMode' => ($guard = app(\Botble\EmironFx\Services\LossGuardService::class))->mode(),
            'lossCosts' => $guard->mode() === 'off' ? [] : $guard->costs(),
            'canBelowCost' => fx_can('fx.counter.below_cost'),
        ]);
    }

    public function open(FxTillCountRequest $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.counter.use'), 403);

        $till = $this->tills->tillFor((int) auth()->id());
        abort_unless($till, 403);

        $this->tills->open($till, (int) auth()->id(), (array) $request->input('counts', []), $request->input('note'));

        return $response->setNextUrl(route('fx.counter.index'))->setMessage(trans('plugins/emiron-fx::fx.counter.opened'));
    }

    public function store(FxTransactionRequest $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.counter.use'), 403);

        $till = $this->tills->tillFor((int) auth()->id());
        $session = $till ? $this->tills->openSession($till) : null;
        abort_unless($session, 403);

        $tx = $this->counter->execute($session, (int) auth()->id(), $request->validated());

        // 0.8.4: ruaj dhe printo pa lëvizur nga faqja (AJAX): kuponi printohet në iframe të fshehur
        if ($request->expectsJson()) {
            return response()->json([
                'error' => false,
                'message' => trans('plugins/emiron-fx::fx.counter.saved', ['number' => $tx->number]),
                'receipt_url' => route('fx.counter.receipt', ['fxTransaction' => $tx->id, 'print' => 1]),
            ]);
        }

        return $response
            ->setNextUrl(route('fx.counter.receipt', ['fxTransaction' => $tx->id, 'print' => 1]))
            ->setMessage(trans('plugins/emiron-fx::fx.counter.saved', ['number' => $tx->number]));
    }

    public function receipt(FxTransaction $fxTransaction)
    {
        abort_unless(
            fx_can('fx.transactions.index') || (fx_can('fx.counter.use') && (int) $fxTransaction->user_id === (int) auth()->id())
            || (fx_can('fx.counter.use') && (int) $fxTransaction->session?->user_id === (int) auth()->id()),
            403
        );

        // 0.8.0: kuponi i kryqëzimit tregon të dyja monedhat (këmbisa 1 + këmbisa 2) si një veprim
        $first = $fxTransaction;

        if (FxTransaction::supportsCross() && (int) $fxTransaction->leg === 2) {
            $first = FxTransaction::query()->findOrFail($fxTransaction->pair_transaction_id);
        }

        $second = (FxTransaction::supportsCross() && (int) $first->leg === 1)
            ? FxTransaction::query()->where('pair_transaction_id', $first->id)->first()
            : null;

        return view('plugins/emiron-fx::counter.receipt', [
            'cross' => $second ? ['first' => $first, 'second' => $second] : null,
            'tx' => $fxTransaction->load(['office', 'till', 'cashier', 'voids', 'voidedBy', 'customer']),
            'autoPrint' => request()->boolean('print'),
            'copy' => request()->boolean('copy'),
        ]);
    }

    public function void(FxTransaction $fxTransaction, FxVoidRequest $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.counter.void'), 403);

        $reversal = $this->counter->void($fxTransaction, (int) auth()->id(), (string) $request->input('reason'));

        if ($request->expectsJson()) {
            return response()->json([
                'error' => false,
                'message' => trans('plugins/emiron-fx::fx.counter.voided', ['number' => $fxTransaction->number, 'reversal' => $reversal->number]),
                'receipt_url' => route('fx.counter.receipt', ['fxTransaction' => $reversal->id, 'print' => 1]),
            ]);
        }

        return $response
            ->setNextUrl(route('fx.counter.receipt', ['fxTransaction' => $reversal->id, 'print' => 1]))
            ->setMessage(trans('plugins/emiron-fx::fx.counter.voided', ['number' => $fxTransaction->number, 'reversal' => $reversal->number]));
    }

    public function movement(FxMovementRequest $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.counter.movement'), 403);

        $till = $this->tills->tillFor((int) auth()->id());
        $session = $till ? $this->tills->openSession($till) : null;
        abort_unless($session, 403);

        $this->tills->movement($session, (string) $request->input('type'), (string) $request->input('currency_code'), (string) $request->input('amount'), (string) $request->input('note'), (int) auth()->id());

        if ($request->expectsJson()) {
            return response()->json(['error' => false, 'message' => trans('plugins/emiron-fx::fx.counter.moved')]);
        }

        return $response->setNextUrl(route('fx.counter.index'))->setMessage(trans('plugins/emiron-fx::fx.counter.moved'));
    }

    public function closeForm()
    {
        abort_unless(fx_can('fx.counter.use'), 403);

        $till = $this->tills->tillFor((int) auth()->id());
        $session = $till ? $this->tills->openSession($till) : null;
        abort_unless($session, 404);

        page_title()->setTitle(trans('plugins/emiron-fx::fx.counter.close'));

        return view('plugins/emiron-fx::counter.close', [
            'till' => $till,
            'session' => $session,
            'balances' => $this->holding->balances($till->id),
            'codes' => $this->tills->currencyCodes($till),
        ]);
    }

    public function close(FxTillCountRequest $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.counter.use'), 403);

        $till = $this->tills->tillFor((int) auth()->id());
        $session = $till ? $this->tills->openSession($till) : null;
        abort_unless($session, 404);

        $this->tills->close($session, (array) $request->input('counts', []), $request->input('note'), (int) auth()->id());

        return $response
            ->setNextUrl(fx_can('fx.sessions.index') ? route('fx.sessions.show', $session->id) : route('fx.counter.index'))
            ->setMessage(trans('plugins/emiron-fx::fx.counter.closed'));
    }
}
