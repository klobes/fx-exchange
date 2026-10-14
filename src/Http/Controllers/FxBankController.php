<?php

namespace Botble\EmironFx\Http\Controllers;

use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Http\Responses\BaseHttpResponse;
use Botble\EmironFx\Models\FxBankAccount;
use Botble\EmironFx\Models\FxBankEntry;
use Botble\EmironFx\Models\FxPartner;
use Botble\EmironFx\Models\FxTillMovement;
use Botble\EmironFx\Services\FxBankService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Banka: llogaritë, lëvizjet me dorë, konfirmimi kundrejt ekstraktit, kundërveprimi. */
class FxBankController extends BaseController
{
    public function __construct(private readonly FxBankService $bank)
    {
    }

    public function index(Request $request)
    {
        abort_unless(fx_can('fx.bank.index'), 403);

        page_title()->setTitle(trans('plugins/emiron-fx::fx.bank.title'));

        $accounts = FxBankAccount::query()->orderBy('bank_name')->get();
        $linked = FxBankEntry::query()->whereNotNull('till_movement_id')->whereDoesntHave('reversal')->pluck('till_movement_id')->all();

        return view('plugins/emiron-fx::bank.index', [
            'accounts' => $accounts,
            'balances' => $accounts->mapWithKeys(fn ($a) => [$a->id => ['all' => $this->bank->balance($a), 'confirmed' => $this->bank->balance($a, true)]]),
            'entries' => FxBankEntry::query()->with(['account', 'partner', 'confirmer', 'author', 'reversal'])
                ->when($request->query('account'), fn ($q, $id) => $q->where('bank_account_id', (int) $id))
                ->orderByDesc('entry_date')->orderByDesc('id')->limit(200)->get(),
            'partners' => FxPartner::query()->where('is_active', true)->orderBy('name')->get(),
            'openMovements' => FxTillMovement::query()->whereIn('type', ['cash_out', 'cash_in'])->whereNotIn('id', $linked ?: [0])->orderBy('id')->get(),
            'canManage' => fx_can('fx.bank.manage'),
            'canConfirm' => fx_can('fx.bank.confirm'),
            'types' => array_keys(FxBankService::SIGNS),
        ]);
    }

    public function storeAccount(Request $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.bank.manage'), 403);

        $data = $request->validate($this->detailRules() + [
            'currency_code' => ['required', 'regex:/^[A-Z]{3}$/'],
            'opening_balance' => ['required', 'numeric'],
            'opening_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $account = FxBankAccount::query()->create($this->clean($data) + ['is_active' => true]);
        \Botble\EmironFx\Models\FxAccessLog::record('bank_account_created', 'bank_account', $account->id, $account->label());

        return $response->setNextUrl(route('fx.bank.index'))->setMessage(trans('core/base::notices.create_success_message'));
    }

    /** 0.8.4: fushat e përshkrimit të llogarisë (të gjitha opsionale përveç emrit të bankës). */
    private function detailRules(): array
    {
        return [
            'bank_name' => ['required', 'string', 'max:120'],
            'iban' => ['nullable', 'string', 'max:40'],
            'account_number' => ['nullable', 'string', 'max:40'],
            'swift' => ['nullable', 'string', 'max:20'],
            'branch' => ['nullable', 'string', 'max:120'],
            'account_holder' => ['nullable', 'string', 'max:160'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    private function clean(array $data): array
    {
        foreach (['iban', 'swift'] as $upper) {
            if (isset($data[$upper])) {
                $data[$upper] = strtoupper(preg_replace('/\s+/', '', (string) $data[$upper]));
            }
        }

        foreach ($data as $key => $value) {
            if (is_string($value) && trim($value) === '') {
                $data[$key] = null;
            } elseif (is_string($value)) {
                $data[$key] = trim($value);
            }
        }

        return $data;
    }

    public function editAccount(FxBankAccount $fxBankAccount)
    {
        abort_unless(fx_can('fx.bank.manage'), 403);

        page_title()->setTitle(trans('plugins/emiron-fx::fx.bank.edit_account'));

        return view('plugins/emiron-fx::bank.edit', [
            'account' => $fxBankAccount,
            'hasEntries' => $fxBankAccount->entries()->exists(),
        ]);
    }

    public function updateAccount(FxBankAccount $fxBankAccount, Request $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.bank.manage'), 403);

        $hasEntries = $fxBankAccount->entries()->exists();

        $rules = $this->detailRules() + ['is_active' => ['nullable', 'boolean']];

        // Monedha dhe gjendja fillestare ndryshohen vetëm kur s'ka ende asnjë lëvizje (nuk prishet historiku)
        if (! $hasEntries) {
            $rules += [
                'currency_code' => ['required', 'regex:/^[A-Z]{3}$/'],
                'opening_balance' => ['required', 'numeric'],
                'opening_date' => ['required', 'date_format:Y-m-d'],
            ];
        }

        $data = $this->clean($request->validate($rules));
        $data['is_active'] = $request->boolean('is_active');

        $fxBankAccount->fill($data);
        $changed = array_keys($fxBankAccount->getDirty());
        $fxBankAccount->save();

        if ($changed) {
            \Botble\EmironFx\Models\FxAccessLog::record('bank_account_updated', 'bank_account', $fxBankAccount->id, implode(', ', $changed));
        }

        return $response->setNextUrl(route('fx.bank.index'))->setMessage(trans('core/base::notices.update_success_message'));
    }

    public function storeEntry(Request $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.bank.manage'), 403);

        $data = $request->validate([
            'bank_account_id' => ['required', 'integer'],
            'type' => ['required', Rule::in(array_keys(FxBankService::SIGNS))],
            'entry_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'amount' => ['required', 'regex:/^\d{1,12}([.,]\d{1,2})?$/'],
            'till_movement_id' => ['nullable', 'integer'],
            'partner_id' => ['nullable', 'integer'],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->bank->record($data, (int) auth()->id());

        return $response->setNextUrl(route('fx.bank.index'))->setMessage(trans('plugins/emiron-fx::fx.bank.saved'));
    }

    public function confirm(FxBankEntry $fxBankEntry, Request $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.bank.confirm'), 403);

        $request->validate(['statement_ref' => ['nullable', 'string', 'max:120']]);
        $this->bank->confirm($fxBankEntry, $request->input('statement_ref'), (int) auth()->id());

        return $response->setNextUrl(url()->previous())->setMessage(trans('plugins/emiron-fx::fx.bank.confirmed'));
    }

    public function reverse(FxBankEntry $fxBankEntry, Request $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.bank.manage'), 403);

        $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $this->bank->reverse($fxBankEntry, (string) $request->input('reason'), (int) auth()->id());

        return $response->setNextUrl(route('fx.bank.index'))->setMessage(trans('plugins/emiron-fx::fx.bank.reversed'));
    }
}
