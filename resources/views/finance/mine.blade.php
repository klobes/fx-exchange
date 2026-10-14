@extends(BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    @if (! $till)
        <div class="alert alert-info">{{ trans('plugins/emiron-fx::fx.counter.no_till_help') }}</div>
    @else
        <div class="row g-3">
            <div class="col-lg-4">
                <div class="card mb-3">
                    <div class="card-header"><h4 class="card-title">{{ $till->name }} · {{ $session ? trans('plugins/emiron-fx::fx.sessions.status_open') : trans('plugins/emiron-fx::fx.dashboard.till_closed') }}</h4></div>
                    <table class="table card-table table-sm">
                        @foreach ($balances as $code => $amount)
                            @continue((float) $amount == 0.0)
                            <tr><td><strong>{{ $code }}</strong></td><td class="text-end font-monospace">{{ number_format((float) $amount, 2, '.', ' ') }}</td></tr>
                        @endforeach
                    </table>
                    <div class="card-footer"><a href="{{ route('fx.counter.index') }}" class="btn btn-primary w-100">{{ trans('plugins/emiron-fx::fx.counter.title') }}</a></div>
                </div>
                <div class="card">
                    <div class="card-header"><h4 class="card-title">{{ trans('plugins/emiron-fx::fx.my.variances') }}</h4></div>
                    <table class="table card-table table-sm">
                        @forelse ($sessions as $s)
                            @php $v = $variances->get($s->id); @endphp
                            <tr>
                                <td class="small">{{ $s->business_date->format('d.m.Y') }}</td>
                                <td class="small text-end font-monospace">
                                    @if ($v)
                                        @foreach ($v as $m)<span class="{{ (float) $m->amount < 0 ? 'text-danger' : 'text-warning' }}">{{ sprintf('%+.2f', (float) $m->amount) }} {{ $m->currency_code }}</span> @endforeach
                                    @else
                                        <span class="text-success">✓</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td class="text-muted small">—</td></tr>
                        @endforelse
                    </table>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="card mb-3">
                    <div class="card-header"><h4 class="card-title">{{ trans('plugins/emiron-fx::fx.my.fx_today') }} ({{ $fx->count() }})</h4></div>
                    <table class="table card-table table-sm">
                        @forelse ($fx as $tx)
                            <tr @class(['text-muted' => $tx->voidedBy || $tx->isReversal()])>
                                <td class="font-monospace small">{{ $tx->number }}</td><td class="small">{{ $tx->occurred_at->format('H:i') }}</td>
                                <td class="small">{{ trans('plugins/emiron-fx::fx.counter.dir_' . $tx->direction) }}</td>
                                <td class="text-end font-monospace small">{{ number_format((float) $tx->foreign_amount, 2, '.', ' ') }} {{ $tx->currency_code }}</td>
                                <td class="text-end font-monospace small">{{ number_format((float) $tx->lek_amount, 0, '.', ' ') }} Lek</td>
                            </tr>
                        @empty
                            <tr><td class="text-muted small">{{ trans('plugins/emiron-fx::fx.counter.none_today') }}</td></tr>
                        @endforelse
                    </table>
                </div>
                <div class="card">
                    <div class="card-header"><h4 class="card-title">{{ trans('plugins/emiron-fx::fx.my.partners_today') }} ({{ $partnerOps->count() }})</h4></div>
                    <table class="table card-table table-sm">
                        @forelse ($partnerOps as $op)
                            <tr @class(['text-muted' => $op->voidedBy || $op->isReversal()])>
                                <td class="font-monospace small">{{ $op->number }}</td><td class="small">{{ $op->occurred_at->format('H:i') }}</td>
                                <td class="small">{{ $op->service?->name }}</td>
                                <td class="text-end font-monospace small {{ $op->direction === 'out' ? 'text-danger' : 'text-success' }}">{{ $op->direction === 'out' ? '−' : '+' }}{{ number_format((float) $op->amount, 2, '.', ' ') }} {{ $op->currency_code }}</td>
                            </tr>
                        @empty
                            <tr><td class="text-muted small">{{ trans('plugins/emiron-fx::fx.counter.none_today') }}</td></tr>
                        @endforelse
                    </table>
                </div>
            </div>
        </div>
    @endif
@endsection
