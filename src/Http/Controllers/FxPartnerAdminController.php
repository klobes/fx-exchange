<?php

namespace Botble\EmironFx\Http\Controllers;

use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Http\Responses\BaseHttpResponse;
use Botble\EmironFx\Models\FxPartner;
use Botble\EmironFx\Models\FxPartnerService;
use Botble\EmironFx\Services\FxPartnerOpsService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Partnerët dhe shërbimet (fx.partners.manage). S'fshihen: çaktivizohen. */
class FxPartnerAdminController extends BaseController
{
    public function index(FxPartnerOpsService $ops)
    {
        abort_unless(fx_can('fx.partners.manage'), 403);

        page_title()->setTitle(trans('plugins/emiron-fx::fx.partners.title'));

        $partners = FxPartner::query()->with('services')->orderBy('name')->get();

        return view('plugins/emiron-fx::partners.index', [
            'partners' => $partners,
            'balances' => $partners->mapWithKeys(fn ($p) => [$p->id => $ops->balances($p->id)]),
        ]);
    }

    public function storePartner(Request $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.partners.manage'), 403);

        $data = $request->validate([
            'id' => ['nullable', 'integer', 'exists:fx_partners,id'],
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'regex:/^[A-Z0-9]{2,10}$/', Rule::unique('fx_partners', 'code')->ignore($request->input('id'))],
            'prepaid_low_alert' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        FxPartner::query()->updateOrCreate(['id' => $data['id'] ?? null], [
            'name' => $data['name'],
            'code' => $data['code'],
            'prepaid_low_alert' => $data['prepaid_low_alert'] ?? 0,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return $response->setNextUrl(route('fx.partners.index'))->setMessage(trans('core/base::notices.update_success_message'));
    }

    public function storeService(Request $request, BaseHttpResponse $response)
    {
        abort_unless(fx_can('fx.partners.manage'), 403);

        $data = $request->validate([
            'id' => ['nullable', 'integer', 'exists:fx_partner_services,id'],
            'partner_id' => ['required', 'integer', 'exists:fx_partners,id'],
            'name' => ['required', 'string', 'max:120'],
            'direction' => ['required', Rule::in(['in', 'out'])],
            'settlement' => ['required', Rule::in(['prefunded', 'account'])],
            'currencies' => ['required', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $currencies = collect(preg_split('/[\s,;]+/', strtoupper($data['currencies'])))->filter(fn ($c) => preg_match('/^[A-Z]{3}$/', $c))->unique()->values()->all();

        if (! $currencies) {
            return $response->setError()->setMessage(trans('plugins/emiron-fx::fx.partners.errors.currency', ['code' => $data['currencies']]));
        }

        FxPartnerService::query()->updateOrCreate(['id' => $data['id'] ?? null], [
            'partner_id' => $data['partner_id'],
            'name' => $data['name'],
            'direction' => $data['direction'],
            'settlement' => $data['settlement'],
            'currencies' => $currencies,
            'is_active' => $request->boolean('is_active', true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        return $response->setNextUrl(route('fx.partners.index'))->setMessage(trans('core/base::notices.update_success_message'));
    }
}
