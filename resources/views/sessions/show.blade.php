@extends(BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><h4 class="card-title">#{{ $session->id }} · {{ $session->till?->name }} · {{ $session->business_date->format('d.m.Y') }}</h4></div>
                <table class="table card-table table-sm mb-0">
                    <tr><td>{{ trans('plugins/emiron-fx::fx.sessions.cashier') }}</td><td>{{ $session->cashier?->name }}</td></tr>
                    <tr><td>{{ trans('plugins/emiron-fx::fx.sessions.status') }}</td><td>{{ trans('plugins/emiron-fx::fx.sessions.status_' . $session->status) }}</td></tr>
                    <tr><td>{{ trans('plugins/emiron-fx::fx.sessions.opened_at') }}</td><td>{{ BaseHelper::formatDateTime($session->opened_at) }}</td></tr>
                    <tr><td>{{ trans('plugins/emiron-fx::fx.sessions.closed_at') }}</td><td>{{ $session->closed_at ? BaseHelper::formatDateTime($session->closed_at) : '—' }}</td></tr>
                    <tr><td>{{ trans('plugins/emiron-fx::fx.sessions.transactions') }}</td><td>{{ $txCount }} (+ {{ $voidCount }} {{ trans('plugins/emiron-fx::fx.counter.reversal') }})</td></tr>
                    @if ($session->approved_at)
                        <tr><td>{{ trans('plugins/emiron-fx::fx.sessions.approved_by') }}</td><td>{{ $session->approver?->name }} · {{ BaseHelper::formatDateTime($session->approved_at) }}</td></tr>
                    @endif
                    @if ($session->opening_note)
                        <tr><td>{{ trans('plugins/emiron-fx::fx.sessions.opening_note') }}</td><td>{{ $session->opening_note }}</td></tr>
                    @endif
                    @if ($session->closing_note)
                        <tr><td>{{ trans('plugins/emiron-fx::fx.sessions.closing_note') }}</td><td>{{ $session->closing_note }}</td></tr>
                    @endif
                </table>
                @if ($canApprove && $session->status === 'closed')
                    <div class="card-footer text-end">
                        <form method="POST" action="{{ route('fx.sessions.approve', $session->id) }}">
                            @csrf
                            <button type="submit" class="btn btn-success">{{ trans('plugins/emiron-fx::fx.sessions.approve') }}</button>
                        </form>
                    </div>
                @endif
            </div>

            @if ($liveBalances !== null)
                <div class="card mt-3">
                    <div class="card-header"><h4 class="card-title">{{ trans('plugins/emiron-fx::fx.sessions.balance_now') }}</h4></div>
                    <table class="table card-table table-sm mb-0">
                        @foreach ($liveBalances as $code => $amount)
                            <tr>
                                <td><strong>{{ $code }}</strong></td>
                                <td class="text-end font-monospace {{ (float) $amount < 0 ? 'text-danger' : '' }}">{{ number_format((float) $amount, 2, '.', ' ') }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            @endif

            @if ($session->closing_counts)
                <div class="card mt-3">
                    <div class="card-header"><h4 class="card-title">{{ trans('plugins/emiron-fx::fx.sessions.reconciliation') }}</h4></div>
                    <table class="table card-table table-sm mb-0">
                        <thead><tr><th></th><th class="text-end">{{ trans('plugins/emiron-fx::fx.counter.expected') }}</th><th class="text-end">{{ trans('plugins/emiron-fx::fx.counter.counted') }}</th><th class="text-end">{{ trans('plugins/emiron-fx::fx.counter.difference') }}</th></tr></thead>
                        @foreach ($session->closing_counts as $code => $counted)
                            @php $exp = (float) ($session->closing_expected[$code] ?? 0); $diff = (float) $counted - $exp; @endphp
                            <tr>
                                <td><strong>{{ $code }}</strong></td>
                                <td class="text-end font-monospace">{{ number_format($exp, 2, '.', ' ') }}</td>
                                <td class="text-end font-monospace">{{ number_format((float) $counted, 2, '.', ' ') }}</td>
                                <td class="text-end font-monospace {{ abs($diff) >= 0.005 ? 'text-danger fw-bold' : 'text-success' }}">{{ sprintf('%+.2f', $diff) }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            @endif
        </div>

        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><h4 class="card-title">{{ trans('plugins/emiron-fx::fx.sessions.movements') }}</h4></div>
                <div class="table-responsive">
                    <table class="table card-table table-sm">
                        <thead><tr><th>{{ trans('plugins/emiron-fx::fx.transactions.time') }}</th><th>{{ trans('plugins/emiron-fx::fx.sessions.type') }}</th><th class="text-end">{{ trans('plugins/emiron-fx::fx.transactions.amount') }}</th><th>{{ trans('plugins/emiron-fx::fx.rates.note') }}</th></tr></thead>
                        @foreach ($movements as $m)
                            <tr>
                                <td class="small">{{ $m->created_at?->format('H:i:s') }}</td>
                                <td class="small">{{ trans('plugins/emiron-fx::fx.movement_types.' . $m->type) }}</td>
                                <td class="text-end font-monospace small {{ (float) $m->amount < 0 ? 'text-danger' : '' }}">{{ number_format((float) $m->amount, 2, '.', ' ') }} {{ $m->currency_code }}</td>
                                <td class="small">{{ $m->note }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
