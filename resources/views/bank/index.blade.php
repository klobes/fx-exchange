@extends(BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <div class="row g-3 mb-3">
        @foreach ($accounts as $account)
            <div class="col-md-4">
                <a href="{{ route('fx.bank.index', ['account' => $account->id]) }}" class="card card-link text-reset">
                    <div class="card-body">
                        <div class="text-muted small">{{ $account->label() }}</div>
                        <div class="fs-3 fw-bold font-monospace">{{ number_format((float) $balances[$account->id]['all'], 2, '.', ' ') }} {{ $account->currency_code }}</div>
                        <div class="small text-muted">{{ trans('plugins/emiron-fx::fx.bank.confirmed_balance') }}: {{ number_format((float) $balances[$account->id]['confirmed'], 2, '.', ' ') }}</div>
                        @if ($account->iban)<div class="small font-monospace mt-1">IBAN {{ $account->iban }}</div>@endif
                        @if ($account->account_number)<div class="small font-monospace">{{ trans('plugins/emiron-fx::fx.bank.account_number') }}: {{ $account->account_number }}</div>@endif
                        @if ($account->swift || $account->branch)<div class="small text-muted">{{ implode(' · ', array_filter([$account->swift, $account->branch])) }}</div>@endif
                        @if ($account->account_holder)<div class="small text-muted">{{ $account->account_holder }}</div>@endif
                        @unless ($account->is_active)<span class="badge text-bg-secondary mt-1">{{ trans('plugins/emiron-fx::fx.bank.inactive') }}</span>@endunless
                    </div>
                </a>
                @if ($canManage)
                    <div class="text-end mt-1"><a href="{{ route('fx.bank.accounts.edit', $account->id) }}" class="btn btn-sm btn-outline-secondary">✎ {{ trans('plugins/emiron-fx::fx.bank.edit_account') }}</a></div>
                @endif
            </div>
        @endforeach
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><h4 class="card-title">{{ trans('plugins/emiron-fx::fx.bank.entries') }}</h4></div>
                <div class="table-responsive">
                    <table class="table card-table table-sm table-vcenter">
                        <thead><tr><th>{{ trans('plugins/emiron-fx::fx.official.date') }}</th><th>{{ trans('plugins/emiron-fx::fx.bank.type') }}</th><th class="text-end">{{ trans('plugins/emiron-fx::fx.transactions.amount') }}</th><th>{{ trans('plugins/emiron-fx::fx.bank.reference') }}</th><th>{{ trans('plugins/emiron-fx::fx.bank.status') }}</th><th></th></tr></thead>
                        @forelse ($entries as $e)
                            <tr @class(['text-muted' => $e->reversal || $e->type === 'reversal'])>
                                <td class="small">{{ $e->entry_date->format('d.m.Y') }}</td>
                                <td class="small">{{ trans('plugins/emiron-fx::fx.bank.types.' . $e->type) }}
                                    @if ($e->partner)<span class="text-muted">· {{ $e->partner->name }}</span>@endif
                                    <div class="text-muted">{{ $e->account?->label() }}</div></td>
                                <td class="text-end font-monospace small {{ (float) $e->amount < 0 ? 'text-danger' : 'text-success' }}">{{ number_format((float) $e->amount, 2, '.', ' ') }}</td>
                                <td class="small">{{ $e->reference }}@if ($e->note)<div class="text-muted">{{ $e->note }}</div>@endif</td>
                                <td class="small">
                                    @if ($e->status === 'confirmed')
                                        <span class="badge text-bg-success">{{ trans('plugins/emiron-fx::fx.bank.status_confirmed') }}</span>
                                        <div class="text-muted">{{ $e->statement_ref }}</div>
                                    @elseif ($canConfirm)
                                        <form method="POST" action="{{ route('fx.bank.confirm', $e->id) }}" class="d-flex gap-1">
                                            @csrf
                                            <input name="statement_ref" class="form-control form-control-sm" placeholder="{{ trans('plugins/emiron-fx::fx.bank.statement_ref') }}">
                                            <button class="btn btn-sm btn-outline-success">✓</button>
                                        </form>
                                    @else
                                        <span class="badge text-bg-warning">{{ trans('plugins/emiron-fx::fx.bank.status_pending') }}</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if ($canManage && ! $e->reversal && $e->type !== 'reversal')
                                        <form method="POST" action="{{ route('fx.bank.reverse', $e->id) }}" data-fx-void data-prompt="{{ trans('plugins/emiron-fx::fx.bank.reverse_prompt') }}">
                                            @csrf
                                            <input type="hidden" name="reason">
                                            <button class="btn btn-sm btn-outline-danger">↺</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-muted">{{ trans('plugins/emiron-fx::fx.bank.none') }}</td></tr>
                        @endforelse
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            @if ($canManage && $accounts->isNotEmpty())
                <form method="POST" action="{{ route('fx.bank.entries.store') }}" class="card mb-3" id="fx-bank-form">
                    @csrf
                    <div class="card-header"><h4 class="card-title">{{ trans('plugins/emiron-fx::fx.bank.new_entry') }}</h4></div>
                    <div class="card-body">
                        <select name="bank_account_id" class="form-select mb-2">
                            @foreach ($accounts->where('is_active', true) as $a)<option value="{{ $a->id }}">{{ $a->label() }}</option>@endforeach
                        </select>
                        <select name="type" class="form-select mb-2" data-fx-bank-type>
                            @foreach ($types as $type)<option value="{{ $type }}" @selected(old('type') === $type)>{{ trans('plugins/emiron-fx::fx.bank.types.' . $type) }}</option>@endforeach
                        </select>
                        <div data-fx-bank-movement>
                            <select name="till_movement_id" class="form-select mb-2">
                                <option value="">{{ trans('plugins/emiron-fx::fx.bank.pick_movement') }}</option>
                                @foreach ($openMovements as $m)
                                    <option value="{{ $m->id }}" data-type="{{ $m->type }}">{{ $m->created_at?->format('d.m H:i') }} · {{ trans('plugins/emiron-fx::fx.movement_types.' . $m->type) }} · {{ number_format(abs((float) $m->amount), 2, '.', ' ') }} {{ $m->currency_code }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div data-fx-bank-partner>
                            <select name="partner_id" class="form-select mb-2">
                                @foreach ($partners as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                            </select>
                        </div>
                        <input type="date" name="entry_date" class="form-control mb-2" value="{{ old('entry_date', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required>
                        <input type="text" inputmode="decimal" name="amount" class="form-control mb-2 text-end font-monospace" placeholder="0.00" value="{{ old('amount') }}" required>
                        <input type="text" name="reference" class="form-control mb-2" maxlength="120" placeholder="{{ trans('plugins/emiron-fx::fx.bank.reference') }}" value="{{ old('reference') }}">
                        <input type="text" name="note" class="form-control" maxlength="500" placeholder="{{ trans('plugins/emiron-fx::fx.rates.note') }}" value="{{ old('note') }}">
                    </div>
                    <div class="card-footer text-end"><button class="btn btn-primary">{{ trans('plugins/emiron-fx::fx.bank.save') }}</button></div>
                </form>
            @endif

            @if ($canManage)
                <form method="POST" action="{{ route('fx.bank.accounts.store') }}" class="card">
                    @csrf
                    <div class="card-header"><h4 class="card-title">{{ trans('plugins/emiron-fx::fx.bank.new_account') }}</h4></div>
                    <div class="card-body">
                        @include('plugins/emiron-fx::bank._account_fields')
                        <hr>
                        <input name="currency_code" class="form-control mb-2 text-uppercase" maxlength="3" placeholder="ALL" required>
                        <input name="opening_balance" class="form-control mb-2 text-end" placeholder="{{ trans('plugins/emiron-fx::fx.bank.opening_balance') }}" required>
                        <input type="date" name="opening_date" class="form-control" value="{{ now()->toDateString() }}" required>
                    </div>
                    <div class="card-footer text-end"><button class="btn btn-outline-primary">{{ trans('plugins/emiron-fx::fx.bank.add_account') }}</button></div>
                </form>
            @endif
        </div>
    </div>

    <script>
        (function () {
            var form = document.getElementById('fx-bank-form');
            if (form) {
                var type = form.querySelector('[data-fx-bank-type]');
                var mov = form.querySelector('[data-fx-bank-movement]');
                var par = form.querySelector('[data-fx-bank-partner]');
                var sel = mov.querySelector('select');
                function refresh() {
                    var t = type.value;
                    var need = t === 'deposit_cash' ? 'cash_out' : (t === 'withdraw_cash' ? 'cash_in' : null);
                    mov.hidden = !need;
                    par.hidden = t.indexOf('partner_') !== 0;
                    Array.prototype.forEach.call(sel.options, function (o) { if (o.value) o.hidden = o.getAttribute('data-type') !== need; });
                    if (sel.selectedOptions[0] && sel.selectedOptions[0].hidden) sel.value = '';
                }
                type.addEventListener('change', refresh);
                refresh();
            }
            document.querySelectorAll('[data-fx-void]').forEach(function (f) {
                f.addEventListener('submit', function (e) {
                    var reason = window.prompt(f.getAttribute('data-prompt'));
                    if (!reason || reason.trim().length < 5) { e.preventDefault(); return; }
                    f.querySelector('input[name="reason"]').value = reason.trim();
                });
            });
        })();
    </script>
@endsection
