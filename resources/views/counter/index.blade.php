@extends(BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    @php
        $lossPlaceholders = ['code' => ':code', 'cost' => ':cost', 'rate' => ':rate'];
        $lossLabels = [
            'warn' => trans('plugins/emiron-fx::fx.counter.below_cost_warn', $lossPlaceholders),
            'block' => trans('plugins/emiron-fx::fx.counter.below_cost_block', $lossPlaceholders),
        ];
        $rateData =$currencies->filter(fn ($c) => $current->has($c->id))->map(fn ($c) => [
            'id' => $c->id,
            'code' => $c->code,
            'unit' => (int) $c->unit,
            'decimals' => min(2, (int) $c->decimals),
            'buy' => (float) $current->get($c->id)->buy_rate,
            'sell' => (float) $current->get($c->id)->sell_rate,
            'balance' => (float) ($balances[$c->code] ?? 0),
            'cost' => isset($lossCosts[$c->code]) ? (float) $lossCosts[$c->code] : null,
        ])->values();
    @endphp

    @include('plugins/emiron-fx::counter._errors')

    @if (! empty($staleCodes))
        <div class="alert alert-warning" role="alert">
            {{ trans('plugins/emiron-fx::fx.counter.stale_rates', ['codes' => implode(', ', $staleCodes)]) }}
        </div>
    @endif

    <div id="fx-ajax-alert" class="alert d-none" role="alert"></div>

    <div class="row g-3">
        <div class="col-lg-7">
            <form method="POST" action="{{ route('fx.counter.store') }}" class="card" id="fx-counter-form"
                  data-rates='@json($rateData)' data-id-threshold="{{ $idThreshold }}" data-lek-decimals="{{ $lekDecimals }}"
                  data-lek-balance="{{ (float) ($balances['ALL'] ?? 0) }}">
                @csrf
                <div class="card-header">
                    <h4 class="card-title">{{ trans('plugins/emiron-fx::fx.counter.new') }}</h4>
                    <div class="card-actions text-muted small">{{ $till->name }} · {{ $session->business_date->format('d.m.Y') }}</div>
                </div>
                <div class="card-body">
                    @if ($rateData->isEmpty())
                        <div class="alert alert-warning mb-0">{{ trans('plugins/emiron-fx::fx.counter.no_rates') }}</div>
                    @else
                        <div class="btn-group w-100 mb-3" role="group">
                            <input type="radio" class="btn-check" name="direction" id="fx-dir-buy" value="buy" @checked(old('direction', 'buy') === 'buy')>
                            <label class="btn btn-outline-primary" for="fx-dir-buy">{{ trans('plugins/emiron-fx::fx.counter.dir_buy_long') }}</label>
                            <input type="radio" class="btn-check" name="direction" id="fx-dir-sell" value="sell" @checked(old('direction') === 'sell')>
                            <label class="btn btn-outline-primary" for="fx-dir-sell">{{ trans('plugins/emiron-fx::fx.counter.dir_sell_long') }}</label>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-4">
                                <label class="form-label" for="fx-currency">{{ trans('plugins/emiron-fx::fx.counter.currency') }}</label>
                                <select id="fx-currency" name="currency_id" class="form-select form-select-lg">
                                    @foreach ($rateData as $r)
                                        <option value="{{ $r['id'] }}" @selected((int) old('currency_id') === $r['id'])>{{ $r['code'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-8">
                                <label class="form-label" for="fx-amount">{{ trans('plugins/emiron-fx::fx.counter.amount') }}</label>
                                <input type="text" inputmode="decimal" id="fx-amount" name="foreign_amount" class="form-control form-control-lg text-end font-monospace"
                                       value="{{ old('foreign_amount') }}" autocomplete="off" autofocus required>
                            </div>
                        </div>

                        {{-- 0.8.0: "Në këmbim të" — Lek (parazgjedhje) ose një monedhë tjetër aktive (kryqëzim valutë↔valutë) --}}
                        @if ($crossEnabled && $rateData->count() > 1)
                            <div class="mb-3" data-fx-pay-box>
                                <label class="form-label mb-1">{{ trans('plugins/emiron-fx::fx.counter.pay_with') }}</label>
                                <div class="btn-group w-100 flex-wrap" role="group">
                                    <input type="radio" class="btn-check" name="pay_currency_id" id="fx-pay-lek" value="" @checked(! old('pay_currency_id'))>
                                    <label class="btn btn-outline-secondary" for="fx-pay-lek">{{ trans('plugins/emiron-fx::fx.counter.pay_lek') }}</label>
                                    @foreach ($rateData as $r)
                                        <input type="radio" class="btn-check" name="pay_currency_id" id="fx-pay-{{ $r['id'] }}" value="{{ $r['id'] }}" data-fx-pay-currency="{{ $r['id'] }}" @checked((int) old('pay_currency_id') === $r['id'])>
                                        <label class="btn btn-outline-secondary" for="fx-pay-{{ $r['id'] }}" data-fx-pay-label="{{ $r['id'] }}">{{ $r['code'] }}</label>
                                    @endforeach
                                </div>
                                <div class="mt-2" data-fx-change-box hidden>
                                    <label class="form-label small mb-1" for="fx-change-in">{{ trans('plugins/emiron-fx::fx.counter.change_in') }}</label>
                                    <select name="change_in" id="fx-change-in" class="form-select form-select-sm">
                                        <option value="lek" @selected(old('change_in', 'lek') === 'lek')>{{ trans('plugins/emiron-fx::fx.counter.change_lek') }}</option>
                                        <option value="given" @selected(old('change_in') === 'given')>{{ trans('plugins/emiron-fx::fx.counter.change_given') }}</option>
                                    </select>
                                </div>
                            </div>
                        @endif

                        <div class="p-3 rounded bg-body-secondary mb-3">
                            <div class="d-flex justify-content-between align-items-baseline">
                                <span class="text-muted" data-fx-side>{{ trans('plugins/emiron-fx::fx.counter.we_pay') }}</span>
                                <span class="fs-1 fw-bold font-monospace" data-fx-lek>0</span>
                            </div>
                            <div class="d-flex justify-content-between small text-muted">
                                <span>{{ trans('plugins/emiron-fx::fx.counter.rate_used') }}: <span data-fx-rate>—</span></span>
                                <span data-fx-balance></span>
                            </div>
                            <div class="small fw-bold mt-1" data-fx-extra hidden></div>
                            <div class="small text-danger fw-bold mt-1" data-fx-warn hidden></div>
                            <div class="alert alert-warning py-2 px-3 mt-2 mb-0 small" data-fx-loss hidden>
                                <div data-fx-loss-text class="fw-bold"></div>
                                @if ($canBelowCost && $lossMode === 'block')
                                    <label class="form-check mt-1 mb-1">
                                        <input type="checkbox" class="form-check-input" name="allow_below_cost" value="1" @checked(old('allow_below_cost'))>
                                        <span class="form-check-label">{{ trans('plugins/emiron-fx::fx.counter.below_cost_allow') }}</span>
                                    </label>
                                    <input type="text" name="below_cost_reason" maxlength="200" class="form-control form-control-sm"
                                           placeholder="{{ trans('plugins/emiron-fx::fx.counter.below_cost_reason') }}" value="{{ old('below_cost_reason') }}">
                                @endif
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="fx-category">{{ trans('plugins/emiron-fx::fx.counter.client_category') }}</label>
                            <select id="fx-category" name="client_category" class="form-select">
                                @foreach ($categories as $category)
                                    <option value="{{ $category }}" @selected(old('client_category', $categories[0] ?? '') === $category)>{{ $category }}</option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Faza 2b: klienti. I detyrueshëm kur shuma > pragut të identifikimit. --}}
                        {{-- 0.8.3: klienti shfaqet i detyrueshëm vetëm kur vlera kalon limitin e BSH pa dokument; përndryshe i mbyllur --}}
                        @php $customerOpen = (bool) (old('customer.doc_number') || old('customer_id')); @endphp
                        <div class="mb-2" data-fx-customer-open-wrap @if ($customerOpen) hidden @endif>
                            <a href="#" class="small" data-fx-customer-open>{{ trans('plugins/emiron-fx::fx.counter.add_customer_optional') }}</a>
                        </div>
                        <div class="border rounded p-3" data-fx-customer-box data-search-url="{{ route('fx.customers.search') }}" @unless ($customerOpen) hidden @endunless>
                            <div class="d-flex justify-content-between align-items-center">
                                <strong>{{ trans('plugins/emiron-fx::fx.counter.customer') }}</strong>
                                <span class="badge text-bg-danger" data-fx-id-required hidden>{{ trans('plugins/emiron-fx::fx.counter.id_required_badge', ['threshold' => number_format((float) $idThreshold, 0, ',', ' ')]) }}</span>
                            </div>
                            <div class="row g-2 mt-1">
                                <div class="col-sm-4">
                                    <select name="customer[doc_type]" class="form-select" data-fx-doc-type>
                                        @foreach ($docTypes as $docType)
                                            <option value="{{ $docType }}" @selected(old('customer.doc_type', 'id_card') === $docType)>{{ trans('plugins/emiron-fx::fx.doc_types.' . $docType) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-sm-5">
                                    <input type="text" name="customer[doc_number]" class="form-control text-uppercase" maxlength="40" autocomplete="off"
                                           value="{{ old('customer.doc_number') }}" placeholder="{{ trans('plugins/emiron-fx::fx.customers.doc_number') }}" data-fx-doc-number>
                                </div>
                                <div class="col-sm-3">
                                    <button type="button" class="btn btn-outline-primary w-100" data-fx-doc-search>{{ trans('plugins/emiron-fx::fx.counter.search') }}</button>
                                </div>
                            </div>
                            <input type="hidden" name="customer_id" value="{{ old('customer_id') }}" data-fx-customer-id>
                            <div class="alert mt-2 mb-0 py-2" data-fx-customer-found hidden></div>

                            <div class="mt-2" data-fx-customer-new hidden>
                                <div class="small text-muted mb-2">{{ trans('plugins/emiron-fx::fx.counter.new_customer') }}</div>
                                <div class="row g-2">
                                    <div class="col-sm-4">
                                        <select name="customer[type]" class="form-select" data-fx-new-type>
                                            <option value="person">{{ trans('plugins/emiron-fx::fx.customers.type_person') }}</option>
                                            <option value="company" @selected(old('customer.type') === 'company')>{{ trans('plugins/emiron-fx::fx.customers.type_company') }}</option>
                                        </select>
                                    </div>
                                    <div class="col-sm-4" data-fx-person><input type="text" name="customer[first_name]" class="form-control" maxlength="80" value="{{ old('customer.first_name') }}" placeholder="{{ trans('plugins/emiron-fx::fx.customers.first_name') }}"></div>
                                    <div class="col-sm-4" data-fx-person><input type="text" name="customer[last_name]" class="form-control" maxlength="80" value="{{ old('customer.last_name') }}" placeholder="{{ trans('plugins/emiron-fx::fx.customers.last_name') }}"></div>
                                    <div class="col-sm-5" data-fx-company hidden><input type="text" name="customer[legal_name]" class="form-control" maxlength="160" value="{{ old('customer.legal_name') }}" placeholder="{{ trans('plugins/emiron-fx::fx.customers.legal_name') }}"></div>
                                    <div class="col-sm-3" data-fx-company hidden><input type="text" name="customer[nipt]" class="form-control text-uppercase" maxlength="20" value="{{ old('customer.nipt') }}" placeholder="NIPT"></div>
                                    <div class="col-sm-4">
                                        <select name="customer[client_category]" class="form-select">
                                            @foreach ($categories as $category)
                                                <option value="{{ $category }}" @selected(old('customer.client_category', 'Individë') === $category)>{{ $category }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-sm-4"><label class="form-label small mb-0">{{ trans('plugins/emiron-fx::fx.customers.doc_expiry') }}</label><input type="date" name="customer[doc_expiry]" class="form-control" value="{{ old('customer.doc_expiry') }}"></div>
                                    <div class="col-sm-4"><label class="form-label small mb-0">{{ trans('plugins/emiron-fx::fx.customers.birth_date') }}</label><input type="date" name="customer[birth_date]" class="form-control" value="{{ old('customer.birth_date') }}"></div>
                                    <div class="col-sm-2"><input type="text" name="customer[nationality]" class="form-control text-uppercase" maxlength="3" value="{{ old('customer.nationality', 'ALB') }}" placeholder="ALB" title="{{ trans('plugins/emiron-fx::fx.customers.nationality') }}"></div>
                                    <div class="col-sm-2"><input type="text" name="customer[doc_country]" class="form-control text-uppercase" maxlength="3" value="{{ old('customer.doc_country', 'ALB') }}" placeholder="ALB" title="{{ trans('plugins/emiron-fx::fx.customers.doc_country') }}"></div>
                                    <div class="col-sm-8"><input type="text" name="customer[address]" class="form-control" maxlength="255" value="{{ old('customer.address') }}" placeholder="{{ trans('plugins/emiron-fx::fx.customers.address') }}"></div>
                                    <div class="col-sm-4"><input type="text" name="customer[phone]" class="form-control" maxlength="40" value="{{ old('customer.phone') }}" placeholder="{{ trans('plugins/emiron-fx::fx.customers.phone') }}"></div>
                                </div>
                            </div>

                            <button type="button" class="btn btn-sm btn-outline-danger mt-2" data-fx-aml hidden
                                    data-prompt="{{ trans('plugins/emiron-fx::fx.counter.aml_prompt') }}">{{ trans('plugins/emiron-fx::fx.counter.aml_button') }}</button>
                        </div>
                    @endif
                </div>
                @if ($rateData->isNotEmpty())
                    <div class="card-footer text-end">
                        <button type="submit" class="btn btn-primary btn-lg">{{ trans('plugins/emiron-fx::fx.counter.save_print') }}</button>
                    </div>
                @endif
            </form>

            {{-- 0.7.0: shërbimet e partnerëve (AK-Invest etj.) në të njëjtën arkë --}}
            <div class="card mt-3" id="partners">
                <div class="card-header"><h4 class="card-title">{{ trans('plugins/emiron-fx::fx.partners.counter_title') }}</h4></div>
                @if ($services->isEmpty())
                    <div class="card-body text-muted">{{ trans('plugins/emiron-fx::fx.partners.none') }}</div>
                @else
                    <form method="POST" action="{{ route('fx.counter.partner') }}" class="card-body" id="fx-partner-form" data-fx-ajax-form>
                        @csrf
                        <div class="row g-2">
                            <div class="col-md-5">
                                <select name="service_id" class="form-select" data-fx-p-service>
                                    @foreach ($services->groupBy(fn ($s) => $s->partner->name) as $partnerName => $group)
                                        <optgroup label="{{ $partnerName }}">
                                            @foreach ($group as $svc)
                                                <option value="{{ $svc->id }}" data-currencies="{{ implode(',', (array) $svc->currencies) }}" data-direction="{{ $svc->direction }}"
                                                    @selected((int) old('service_id') === $svc->id)>{{ $svc->name }} ({{ strtoupper($svc->direction) }})</option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2"><select name="currency_code" class="form-select" data-fx-p-currency></select></div>
                            <div class="col-md-2"><input type="text" inputmode="decimal" name="amount" class="form-control text-end font-monospace" placeholder="0.00" required value="{{ old('amount') }}"></div>
                            <div class="col-md-3"><input type="text" name="reference" class="form-control" maxlength="80" placeholder="{{ trans('plugins/emiron-fx::fx.partners.reference') }}" value="{{ old('reference') }}"></div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-2">
                            <span class="small" data-fx-p-hint></span>
                            <button class="btn btn-outline-primary">{{ trans('plugins/emiron-fx::fx.partners.save') }}</button>
                        </div>
                    </form>
                    <div class="table-responsive" id="fx-partner-ops">
                        <table class="table card-table table-sm table-vcenter">
                            @forelse ($partnerOps as $op)
                                <tr @class(['text-muted' => $op->voidedBy || $op->isReversal()])>
                                    <td class="font-monospace small">{{ $op->number }}</td>
                                    <td class="small">{{ $op->occurred_at->format('H:i') }}</td>
                                    <td class="small">
                                        @if ($op->isReversal())<span class="badge text-bg-danger">{{ trans('plugins/emiron-fx::fx.counter.reversal') }}</span>@endif
                                        @if ($op->voidedBy)<s>{{ $op->service?->name }}</s>@else{{ $op->service?->name }}@endif
                                        @if ($op->reference)<span class="text-muted">· {{ $op->reference }}</span>@endif
                                    </td>
                                    <td class="text-end font-monospace small {{ $op->direction === 'out' ? 'text-danger' : 'text-success' }}">{{ $op->direction === 'out' ? '−' : '+' }}{{ number_format((float) $op->amount, 2, '.', ' ') }} {{ $op->currency_code }}</td>
                                    <td class="text-end">
                                        @if ($canVoid && ! $op->isReversal() && ! $op->voidedBy)
                                            <form method="POST" action="{{ route('fx.counter.partner.void', $op->id) }}" class="d-inline" data-fx-void data-fx-ajax
                                                  data-prompt="{{ trans('plugins/emiron-fx::fx.counter.void_prompt', ['number' => $op->number]) }}">
                                                @csrf
                                                <input type="hidden" name="reason">
                                                <button class="btn btn-sm btn-outline-danger">{{ trans('plugins/emiron-fx::fx.counter.void') }}</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td class="text-muted small">{{ trans('plugins/emiron-fx::fx.counter.none_today') }}</td></tr>
                            @endforelse
                        </table>
                    </div>
                @endif
            </div>

            <div class="card mt-3" id="fx-today-card">
                <div class="card-header"><h4 class="card-title">{{ trans('plugins/emiron-fx::fx.counter.today') }}</h4></div>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table table-sm">
                        <thead>
                            <tr>
                                <th>{{ trans('plugins/emiron-fx::fx.transactions.number') }}</th>
                                <th>{{ trans('plugins/emiron-fx::fx.transactions.time') }}</th>
                                <th>{{ trans('plugins/emiron-fx::fx.transactions.direction') }}</th>
                                <th class="text-end">{{ trans('plugins/emiron-fx::fx.transactions.amount') }}</th>
                                <th class="text-end">Lek</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($transactions as $tx)
                                <tr @class(['text-muted' => $tx->voidedBy || $tx->isReversal()])>
                                    <td class="font-monospace small">{{ $tx->number }}</td>
                                    <td class="small">{{ $tx->occurred_at->format('H:i') }}</td>
                                    @php $pair = $tx->isCross() ? $tx->pairLeg : null; @endphp
                                    <td class="small">
                                        @if ($tx->isReversal())
                                            <span class="badge text-bg-danger">{{ trans('plugins/emiron-fx::fx.counter.reversal') }}</span>
                                        @endif
                                        @php $dirText = $pair ? trans('plugins/emiron-fx::fx.counter.dir_cross') : trans('plugins/emiron-fx::fx.counter.dir_' . $tx->direction); @endphp
                                        @if ($tx->voidedBy)
                                            <s>{{ $dirText }}</s>
                                        @else
                                            {{ $dirText }}
                                        @endif
                                        @foreach ((array) $tx->flags as $flag)
                                            @continue($flag === 'cross')
                                            <span class="badge text-bg-{{ $flag === 'aif_cash' ? 'danger' : 'warning' }}">{{ trans('plugins/emiron-fx::fx.flags.' . $flag) }}</span>
                                        @endforeach
                                    </td>
                                    <td class="text-end font-monospace small">
                                        @if ($pair)
                                            {{ number_format((float) $tx->foreign_amount, 2, '.', ' ') }} {{ $tx->currency_code }} → {{ number_format((float) $pair->foreign_amount, 2, '.', ' ') }} {{ $pair->currency_code }}
                                        @else
                                            {{ number_format((float) $tx->foreign_amount, 2, '.', ' ') }} {{ $tx->currency_code }}
                                        @endif
                                    </td>
                                    <td class="text-end font-monospace small">{{ number_format((float) $tx->lek_amount, 0, '.', ' ') }}</td>
                                    <td class="text-end text-nowrap">
                                        <a href="{{ route('fx.counter.receipt', ['fxTransaction' => $tx->id, 'copy' => 1]) }}" target="_blank" class="btn btn-sm btn-outline-secondary">{{ trans('plugins/emiron-fx::fx.counter.receipt') }}</a>
                                        @if ($canVoid && ! $tx->isReversal() && ! $tx->voidedBy)
                                            <form method="POST" action="{{ route('fx.counter.void', $tx->id) }}" class="d-inline" data-fx-void data-fx-ajax
                                                  data-prompt="{{ trans('plugins/emiron-fx::fx.counter.void_prompt', ['number' => $tx->number]) }}">
                                                @csrf
                                                <input type="hidden" name="reason">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">{{ trans('plugins/emiron-fx::fx.counter.void') }}</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-muted">{{ trans('plugins/emiron-fx::fx.counter.none_today') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card" id="fx-balances-card">
                <div class="card-header"><h4 class="card-title">{{ trans('plugins/emiron-fx::fx.counter.till_balance') }}</h4></div>
                <table class="table card-table table-sm mb-0">
                    @foreach ($codes as $code)
                        <tr>
                            <td><strong>{{ $code }}</strong></td>
                            <td class="text-end font-monospace">{{ number_format((float) ($balances[$code] ?? 0), 2, '.', ' ') }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>

            @if (! empty($prepaid))
                {{-- 0.8.4: gjendja e mbetur e parapaguar; e kuqe nën pragun e partnerit --}}
                <div class="card mt-3" id="fx-prepaid-card">
                    <div class="card-header"><h4 class="card-title">{{ trans('plugins/emiron-fx::fx.partners.prepaid_title') }}</h4></div>
                    <table class="table card-table table-sm mb-0">
                        @foreach ($prepaid as $row)
                            <tr @class(['table-danger' => $row['low']])>
                                <td>
                                    <strong>{{ $row['partner']->name }}</strong>
                                    @if ($row['low'])
                                        <div class="small text-danger fw-bold">{{ trans('plugins/emiron-fx::fx.partners.prepaid_low_hint', ['limit' => number_format((float) $row['alert'], 0, '.', ' ')]) }}</div>
                                    @endif
                                </td>
                                <td class="text-end font-monospace">
                                    {{ number_format((float) $row['balance'], 2, '.', ' ') }} ALL
                                    @foreach ($row['others'] as $code => $value)
                                        <div class="small text-muted">{{ number_format((float) $value, 2, '.', ' ') }} {{ $code }}</div>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            @endif

            <div class="card mt-3" id="fx-holding-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <strong>{{ trans('plugins/emiron-fx::fx.counter.holding') }}</strong>
                        <span class="font-monospace">{{ number_format((float) $holding['before'], 0, '.', ' ') }} / {{ number_format((float) $holding['limit'], 0, '.', ' ') }} Lek</span>
                    </div>
                    <div class="progress mt-2">
                        <div class="progress-bar {{ $holding['exceeds'] ? 'bg-danger' : ($holding['warn'] ? 'bg-warning' : 'bg-success') }}" style="width: {{ min(100, $holding['percent']) }}%"></div>
                    </div>
                    <div class="small text-muted mt-1">{{ trans('plugins/emiron-fx::fx.counter.holding_help') }}</div>
                </div>
            </div>

            @if ($canMove)
                <form method="POST" action="{{ route('fx.counter.movement') }}" class="card mt-3" data-fx-ajax-form>
                    @csrf
                    <div class="card-header"><h4 class="card-title">{{ trans('plugins/emiron-fx::fx.counter.movement') }}</h4></div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-6">
                                <select name="type" class="form-select">
                                    <option value="cash_in">{{ trans('plugins/emiron-fx::fx.counter.cash_in') }}</option>
                                    <option value="cash_out">{{ trans('plugins/emiron-fx::fx.counter.cash_out') }}</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <select name="currency_code" class="form-select">
                                    @foreach ($codes as $code)
                                        <option value="{{ $code }}">{{ $code }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <input type="text" inputmode="decimal" name="amount" class="form-control text-end font-monospace" placeholder="0.00" required>
                            </div>
                            <div class="col-12">
                                <input type="text" name="note" class="form-control" maxlength="500" placeholder="{{ trans('plugins/emiron-fx::fx.counter.movement_note') }}" required>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer text-end">
                        <button type="submit" class="btn btn-outline-primary">{{ trans('plugins/emiron-fx::fx.counter.movement_save') }}</button>
                    </div>
                </form>
            @endif

            <a href="{{ route('fx.rates.board') }}" target="_blank" rel="noopener" class="btn btn-outline-secondary w-100 mt-3">{{ trans('plugins/emiron-fx::fx.counter.print_board') }}</a>
            <a href="{{ route('fx.counter.close-form') }}" class="btn btn-outline-danger w-100 mt-3">{{ trans('plugins/emiron-fx::fx.counter.close') }}</a>
        </div>
    </div>

    <form method="POST" action="{{ route('fx.aml.store') }}" id="fx-aml-form" hidden>
        @csrf
        <input type="hidden" name="customer_id">
        <input type="hidden" name="description">
    </form>

    <script>
        (function () {
            var form = document.getElementById('fx-counter-form');
            if (!form) return;
            var rates = {};
            try { JSON.parse(form.getAttribute('data-rates') || '[]').forEach(function (r) { rates[r.id] = r; }); } catch (e) {}
            var idThreshold = parseFloat(form.getAttribute('data-id-threshold')) || 100000;
            var lekDec = parseInt(form.getAttribute('data-lek-decimals'), 10) || 0;
            var lekBalance = parseFloat(form.getAttribute('data-lek-balance')) || 0;
            var amount = form.querySelector('#fx-amount');
            var currency = form.querySelector('#fx-currency');
            if (!amount || !currency) return;
            var out = form.querySelector('[data-fx-lek]');
            var rateEl = form.querySelector('[data-fx-rate]');
            var sideEl = form.querySelector('[data-fx-side]');
            var balEl = form.querySelector('[data-fx-balance]');
            var warnEl = form.querySelector('[data-fx-warn]');
            var idBadge = form.querySelector('[data-fx-id-required]');
            var labels = {
                pay: @json(trans('plugins/emiron-fx::fx.counter.we_pay')),
                receive: @json(trans('plugins/emiron-fx::fx.counter.we_receive')),
                short: @json(trans('plugins/emiron-fx::fx.counter.short_js')),
                inTill: @json(trans('plugins/emiron-fx::fx.counter.in_till_js')),
                crossGives: @json(trans('plugins/emiron-fx::fx.counter.cross_gives_js')),
                crossGets: @json(trans('plugins/emiron-fx::fx.counter.cross_gets_js')),
                crossChange: @json(trans('plugins/emiron-fx::fx.counter.cross_change_js'))
            };
            var lossEl = form.querySelector('[data-fx-loss]');
            var lossText = form.querySelector('[data-fx-loss-text]');
            var lossMode = @json($lossMode);
            var lossLabels = @json($lossLabels);

            // 0.8.4 — shitje (zyra SHET valutë) me kurs nën koston e stokut: paralajmërim / bllokim (serveri vendos)
            function lossCheck(sold, sellRate) {
                if (!lossEl) return;
                var show = !!sold && lossMode !== 'off' && sold.cost !== null && sold.cost !== undefined && sellRate < sold.cost - 1e-9;
                lossEl.hidden = !show;
                if (show && lossText) {
                    lossText.textContent = (lossMode === 'block' ? lossLabels.block : lossLabels.warn)
                        .replace(':code', sold.code).replace(':cost', sold.cost.toFixed(4)).replace(':rate', sellRate.toFixed(4));
                }
            }

            var extraEl = form.querySelector('[data-fx-extra]');
            var changeBox = form.querySelector('[data-fx-change-box]');
            var changeSel = form.querySelector('#fx-change-in');

            function fmt(n, d) { return n.toLocaleString('sq-AL', { minimumFractionDigits: d, maximumFractionDigits: d }); }

            // 0.8.3 — fushat e klientit janë të detyrueshme (dhe të dukshme) VETËM kur vlera në Lek kalon limitin e BSH
            // për këmbim pa dokument identifikimi; përndryshe mbyllen (opsionale, me lidhje "Shto klient").
            function needId(flag) {
                idBadge.hidden = !flag;
                window.__fxNeedId = !!flag;
                document.dispatchEvent(new CustomEvent('fx:need-id', { detail: { need: !!flag } }));
            }

            // 0.8.0 — kryqëzimi valutë↔valutë: pasqyrim i parashikimit (serveri llogarit përfundimtarisht).
            function floorTo(x, d) { var m = Math.pow(10, d); return Math.floor(x * m + 1e-9) / m; }
            function ceilTo(x, d) { var m = Math.pow(10, d); return Math.ceil(x * m - 1e-9) / m; }
            function rndLek(x) { var m = Math.pow(10, lekDec); return Math.round(x * m) / m; }

            function payValue() {
                var c = form.querySelector('input[name="pay_currency_id"]:checked');
                return c ? c.value : '';
            }

            function syncPay() {
                // monedha kryesore s'mund të jetë edhe monedha "në këmbim të"
                form.querySelectorAll('[data-fx-pay-currency]').forEach(function (inp) {
                    var same = inp.value === currency.value;
                    inp.disabled = same;
                    var lab = form.querySelector('[data-fx-pay-label="' + inp.value + '"]');
                    if (lab) lab.hidden = same;
                    if (same && inp.checked) { var lek = form.querySelector('#fx-pay-lek'); if (lek) lek.checked = true; }
                });
            }

            function crossCalc(v, dir, main, other) {
                var A, B, a, b, L1, L2, change = 0, changeA = 0;
                var given = (changeSel && changeSel.value === 'given');
                if (dir === 'buy') {
                    A = main; B = other; a = v;
                    L1 = rndLek(a * A.buy / A.unit);
                    b = floorTo(L1 * B.unit / B.sell, B.decimals);
                    L2 = Math.min(L1, rndLek(b * B.sell / B.unit));
                    if (given) { var used = Math.min(a, ceilTo(L2 * A.unit / A.buy, A.decimals)); changeA = a - used; a = used; L1 = L2; }
                    else { change = L1 - L2; }
                } else {
                    B = main; A = other; b = v;
                    L2 = rndLek(b * B.sell / B.unit);
                    a = ceilTo(L2 * A.unit / A.buy, A.decimals);
                    L1 = L2;
                }

                if (!(a > 0) || !(b > 0)) { out.textContent = '0'; warnEl.hidden = true; needId(false); balEl.textContent = ''; if (extraEl) extraEl.hidden = true; lossCheck(null, 0); return; }
                lossCheck(B, B.sell);

                if (dir === 'buy') { sideEl.textContent = labels.crossGets + ' ' + B.code; out.textContent = fmt(b, B.decimals); }
                else { sideEl.textContent = labels.crossGives + ' ' + A.code; out.textContent = fmt(a, A.decimals); }

                var eff = a > 0 ? b / a : 0;
                rateEl.textContent = '1 ' + A.code + ' = ' + eff.toFixed(4).replace(/0+$/, '').replace(/\.$/, '') + ' ' + B.code;

                var shortOf = null;
                if (b > B.balance) shortOf = B.code;
                else if (change > 0 && change > lekBalance) shortOf = 'ALL';
                balEl.textContent = labels.inTill + ' ' + fmt(B.balance, 2) + ' ' + B.code;
                warnEl.hidden = !shortOf;
                warnEl.textContent = shortOf ? labels.short + ' ' + shortOf : '';
                needId(L1 > idThreshold);

                if (extraEl) {
                    var parts = [];
                    if (change > 0) parts.push(labels.crossChange + ' ' + fmt(change, lekDec) + ' Lek');
                    if (changeA > 0) parts.push(labels.crossChange + ' ' + fmt(changeA, A.decimals) + ' ' + A.code);
                    extraEl.textContent = parts.join(' · ');
                    extraEl.hidden = parts.length === 0;
                }
            }

            function calc() {
                var r = rates[currency.value];
                var dir = form.querySelector('input[name="direction"]:checked').value;
                var v = parseFloat((amount.value || '').replace(/\s/g, '').replace(',', '.'));
                if (!r) return;

                syncPay();
                var pay = payValue();
                if (changeBox) changeBox.hidden = !(pay !== '' && dir === 'buy');
                if (pay !== '' && rates[pay]) {
                    if (isNaN(v) || v <= 0) { lossCheck(null, 0); out.textContent = '0'; warnEl.hidden = true; needId(false); balEl.textContent = ''; if (extraEl) extraEl.hidden = true; sideEl.textContent = dir === 'buy' ? labels.crossGets : labels.crossGives; return; }
                    crossCalc(v, dir, r, rates[pay]);
                    return;
                }
                if (extraEl) extraEl.hidden = true;
                var rate = dir === 'buy' ? r.buy : r.sell;
                rateEl.textContent = rate.toFixed(4).replace(/0{1,2}$/, '') + (r.unit > 1 ? ' / ' + r.unit : '') + ' Lek';
                sideEl.textContent = dir === 'buy' ? labels.pay : labels.receive;

                if (isNaN(v) || v <= 0) { lossCheck(null, 0); out.textContent = '0'; warnEl.hidden = true; needId(false); balEl.textContent = ''; return; }
                lossCheck(dir === 'sell' ? r : null, r.sell);

                var lek = Math.round((v * rate / r.unit) * Math.pow(10, lekDec)) / Math.pow(10, lekDec);
                out.textContent = fmt(lek, lekDec);

                var shortOf = dir === 'buy' ? (lek > lekBalance ? 'ALL' : null) : (v > r.balance ? r.code : null);
                balEl.textContent = dir === 'buy' ? labels.inTill + ' ' + fmt(lekBalance, 0) + ' ALL' : labels.inTill + ' ' + fmt(r.balance, 2) + ' ' + r.code;
                warnEl.hidden = !shortOf;
                warnEl.textContent = shortOf ? labels.short + ' ' + shortOf : '';
                needId(lek > idThreshold);
            }

            ['input', 'change'].forEach(function (ev) { form.addEventListener(ev, calc); });

            // 0.8.4 — pas çdo veprimi (AJAX) kurset/gjendja rilexohen nga atributet e reja dhe forma pastrohet
            document.addEventListener('fx:refresh', function () {
                rates = {};
                try { JSON.parse(form.getAttribute('data-rates') || '[]').forEach(function (r) { rates[r.id] = r; }); } catch (e) {}
                lekBalance = parseFloat(form.getAttribute('data-lek-balance')) || 0;
                calc();
            });

            calc();
        })();

        (function () {
            var box = document.querySelector('[data-fx-customer-box]');
            if (!box) return;
            var url = box.getAttribute('data-search-url');
            var docType = box.querySelector('[data-fx-doc-type]');
            var docNumber = box.querySelector('[data-fx-doc-number]');
            var idInput = box.querySelector('[data-fx-customer-id]');
            var found = box.querySelector('[data-fx-customer-found]');
            var fresh = box.querySelector('[data-fx-customer-new]');
            var amlBtn = box.querySelector('[data-fx-aml]');
            var newType = box.querySelector('[data-fx-new-type]');
            var txt = {
                notFound: @json(trans('plugins/emiron-fx::fx.counter.not_found')),
                blocked: @json(trans('plugins/emiron-fx::fx.counter.errors.customer_blocked')),
                expired: @json(trans('plugins/emiron-fx::fx.counter.errors.doc_expired')),
                pep: @json(trans('plugins/emiron-fx::fx.counter.pep_badge'))
            };

            // 0.8.3 — detyrueshmëria dhe dukshmëria sipas limitit të BSH (nga llogaritja e shumës te forma lart)
            var needId = !!window.__fxNeedId;
            var opened = !box.hidden;
            var openWrap = document.querySelector('[data-fx-customer-open-wrap]');
            var openLink = document.querySelector('[data-fx-customer-open]');

            function applyNeed() {
                box.hidden = !needId && !opened;
                if (openWrap) openWrap.hidden = needId || opened;
                docNumber.required = needId;

                var freshVisible = !fresh.hidden;
                var company = newType.value === 'company';
                box.querySelectorAll('[data-fx-person] input').forEach(function (i) { i.required = needId && freshVisible && !company; });
                box.querySelectorAll('[data-fx-company] input[name="customer[legal_name]"]').forEach(function (i) { i.required = needId && freshVisible && company; });
            }

            document.addEventListener('fx:need-id', function (e) { needId = !!(e.detail && e.detail.need); applyNeed(); });

            // 0.8.4 — pas një veprimi të ruajtur: bllok i pastër klienti
            document.addEventListener('fx:reset-customer', function () {
                idInput.value = ''; found.hidden = true; fresh.hidden = true; amlBtn.hidden = true;
                opened = false; needId = false; applyNeed();
            });

            if (openLink) {
                openLink.addEventListener('click', function (e) { e.preventDefault(); opened = true; applyNeed(); });
            }

            function reset() { idInput.value = ''; found.hidden = true; amlBtn.hidden = true; }

            function toggleType() {
                var company = newType.value === 'company';
                box.querySelectorAll('[data-fx-person]').forEach(function (el) { el.hidden = company; });
                box.querySelectorAll('[data-fx-company]').forEach(function (el) { el.hidden = !company; });
                applyNeed();
            }

            function search() {
                var num = (docNumber.value || '').trim();
                if (num.length < 4) return;
                fetch(url + '?doc_type=' + encodeURIComponent(docType.value) + '&doc_number=' + encodeURIComponent(num), {
                    credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                }).then(function (r) { return r.json(); }).then(function (c) {
                    if (!c.found) {
                        reset();
                        fresh.hidden = false; applyNeed();
                        found.hidden = false;
                        found.className = 'alert alert-info mt-2 mb-0 py-2';
                        found.textContent = txt.notFound;
                        return;
                    }
                    fresh.hidden = true; applyNeed();
                    found.hidden = false;
                    var bad = c.is_blocked || c.doc_expired;
                    found.className = 'alert mt-2 mb-0 py-2 ' + (bad ? 'alert-danger' : (c.is_pep ? 'alert-warning' : 'alert-success'));
                    found.textContent = c.name + ' · ' + c.category + ' · ' + c.doc
                        + (c.is_pep ? ' · ' + txt.pep : '')
                        + (c.is_blocked ? ' · ' + txt.blocked : '')
                        + (c.doc_expired ? ' · ' + txt.expired : '');
                    idInput.value = bad ? '' : c.id;
                    amlBtn.hidden = false;
                    amlBtn.setAttribute('data-customer', c.id);
                });
            }

            box.querySelector('[data-fx-doc-search]').addEventListener('click', search);
            docNumber.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); search(); } });
            docNumber.addEventListener('input', reset);
            docType.addEventListener('change', reset);
            newType.addEventListener('change', toggleType);
            toggleType();
            if (docNumber.value && !idInput.value) { fresh.hidden = false; applyNeed(); }

            amlBtn.addEventListener('click', function () {
                var d = window.prompt(amlBtn.getAttribute('data-prompt'));
                if (!d || d.trim().length < 10) return;
                var f = document.getElementById('fx-aml-form');
                f.querySelector('[name="customer_id"]').value = amlBtn.getAttribute('data-customer');
                f.querySelector('[name="description"]').value = d.trim();
                f.submit();
            });
        })();

        (function () {
            var form = document.getElementById('fx-partner-form');
            if (!form) return;
            var svc = form.querySelector('[data-fx-p-service]');
            var cur = form.querySelector('[data-fx-p-currency]');
            var hint = form.querySelector('[data-fx-p-hint]');
            var old = @json(old('currency_code'));
            var txt = { in: @json(trans('plugins/emiron-fx::fx.partners.hint_in')), out: @json(trans('plugins/emiron-fx::fx.partners.hint_out')) };
            function refresh() {
                var opt = svc.options[svc.selectedIndex];
                var list = (opt.getAttribute('data-currencies') || 'ALL').split(',');
                cur.innerHTML = '';
                list.forEach(function (c) { var o = document.createElement('option'); o.value = c; o.textContent = c; if (c === old) o.selected = true; cur.appendChild(o); });
                var dir = opt.getAttribute('data-direction');
                hint.textContent = txt[dir] || '';
                hint.className = 'small ' + (dir === 'out' ? 'text-danger' : 'text-success');
            }
            svc.addEventListener('change', function () { old = null; refresh(); });
            refresh();
        })();

        // 0.8.4 — ruaj/anulo + printo pa ndërruar faqe: POST me AJAX, kuponi printohet në iframe të fshehur,
        // pastaj rifreskohen vetëm gjendja e arkës, lista e veprimeve dhe kurset (pa rifreskuar faqen).
        (function () {
            var alertEl = document.getElementById('fx-ajax-alert');
            var timer = null;

            function show(kind, text) {
                if (!alertEl) { window.alert(text); return; }
                alertEl.className = 'alert alert-' + kind;
                alertEl.textContent = text;
                window.scrollTo({ top: 0, behavior: 'smooth' });
                clearTimeout(timer);
                if (kind === 'success') timer = setTimeout(function () { alertEl.className = 'alert d-none'; }, 6000);
            }

            function printReceipt(url) {
                var old = document.getElementById('fx-print-frame');
                if (old) old.remove();
                var fr = document.createElement('iframe');
                fr.id = 'fx-print-frame';
                fr.setAttribute('aria-hidden', 'true');
                fr.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
                fr.src = url; // kuponi ka autoPrint: window.print() në load
                document.body.appendChild(fr);
            }

            function refreshParts() {
                return fetch(window.location.href, { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
                    .then(function (r) { return r.text(); })
                    .then(function (html) {
                        var doc = new DOMParser().parseFromString(html, 'text/html');
                        ['fx-today-card', 'fx-balances-card', 'fx-holding-card', 'fx-partner-ops', 'fx-prepaid-card'].forEach(function (id) {
                            var fresh = doc.getElementById(id), cur = document.getElementById(id);
                            if (fresh && cur) cur.innerHTML = fresh.innerHTML;
                        });
                        var f = document.getElementById('fx-counter-form'), nf = doc.getElementById('fx-counter-form');
                        if (f && nf) {
                            ['data-rates', 'data-lek-balance', 'data-id-threshold'].forEach(function (a) {
                                if (nf.hasAttribute(a)) f.setAttribute(a, nf.getAttribute(a));
                            });
                        }
                        document.dispatchEvent(new CustomEvent('fx:refresh'));
                    });
            }

            function errorText(status, body) {
                if (body && body.errors) {
                    var out = [];
                    Object.keys(body.errors).forEach(function (k) { [].concat(body.errors[k]).forEach(function (m) { out.push(m); }); });
                    if (out.length) return out.join(' ');
                }
                if (body && body.message) return body.message;
                return 'Gabim (' + status + '). Rifresko faqen dhe provo përsëri.';
            }

            function send(form, after) {
                var btn = form.querySelector('button[type="submit"], button:not([type])');
                if (btn) btn.disabled = true;
                fetch(form.action, {
                    method: 'POST', credentials: 'same-origin', body: new FormData(form),
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                }).then(function (r) {
                    return r.json().catch(function () { return null; }).then(function (body) { return { ok: r.ok, status: r.status, body: body }; });
                }).then(function (res) {
                    if (btn) btn.disabled = false;
                    if (!res.ok || !res.body || res.body.error) { show('danger', errorText(res.status, res.body)); return; }
                    show('success', res.body.message || '');
                    if (res.body.receipt_url) printReceipt(res.body.receipt_url);
                    if (after) after();
                    return refreshParts();
                }).catch(function () {
                    if (btn) btn.disabled = false;
                    show('danger', 'Lidhja dështoi. Kontrollo nëse veprimi u regjistrua te lista përpara se ta përsërisësh.');
                });
            }

            document.addEventListener('submit', function (e) {
                var f = e.target;

                if (f.id === 'fx-counter-form') {
                    e.preventDefault();
                    send(f, function () {
                        f.reset();
                        document.dispatchEvent(new CustomEvent('fx:reset-customer'));
                        var amt = f.querySelector('#fx-amount');
                        if (amt) amt.focus();
                    });
                    return;
                }

                // furnizim/derdhje arke dhe veprime me partnerët: ruaj pa rifreskuar faqen
                if (f.hasAttribute && f.hasAttribute('data-fx-ajax-form')) {
                    e.preventDefault();
                    send(f, function () {
                        f.reset();
                        var svc = f.querySelector('[data-fx-p-service]');
                        if (svc) svc.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                    return;
                }

                if (f.hasAttribute && f.hasAttribute('data-fx-void')) {
                    var reason = window.prompt(f.getAttribute('data-prompt'));
                    if (!reason || reason.trim().length < 5) { e.preventDefault(); return; }
                    f.querySelector('input[name="reason"]').value = reason.trim();

                    if (f.hasAttribute('data-fx-ajax')) {
                        e.preventDefault();
                        send(f, null);
                    }
                }
            });
        })();
    </script>
@endsection
