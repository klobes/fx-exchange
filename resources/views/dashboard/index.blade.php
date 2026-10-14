@extends(BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h3 class="mb-0">{{ $d['date']->format('d.m.Y') }} @if ($d['holiday'])<span class="badge text-bg-info">{{ $d['holiday'] }}</span>@endif</h3>
        <form method="GET" class="d-flex gap-2">
            <input type="date" name="date" class="form-control" value="{{ $d['date']->toDateString() }}" max="{{ now()->toDateString() }}">
            <button class="btn btn-outline-secondary">{{ trans('plugins/emiron-fx::fx.reports.show') }}</button>
        </form>
    </div>

    @if ($d['pending'])
        <div class="alert alert-warning">
            <strong>{{ trans('plugins/emiron-fx::fx.dashboard.pending') }}</strong>
            @foreach ($d['pending'] as $p)
                <div>{{ $p['date']->format('d.m.Y') }} — {{ trans('plugins/emiron-fx::fx.dashboard.deadline', ['deadline' => $p['deadline']->format('d.m.Y H:i')]) }}
                    <a href="{{ route('fx.reports.index', ['date' => $p['date']->toDateString()]) }}">{{ trans('plugins/emiron-fx::fx.reports.title') }} →</a></div>
            @endforeach
        </div>
    @endif

    @if ($canAml && $d['amlOpen'] > 0)
        <div class="alert alert-danger">{{ trans('plugins/emiron-fx::fx.dashboard.aml_open', ['count' => $d['amlOpen']]) }} <a href="{{ route('fx.aml.index') }}">{{ trans('plugins/emiron-fx::fx.aml.title') }} →</a></div>
    @endif

    @php
        $tiles = [
            ['dashboard.transactions', number_format($d['count'])],
            ['dashboard.identified', number_format($d['identified'])],
            ['dashboard.aif', number_format($d['aif'])],
        ];

        if ($canPnl) {
            $tiles[] = ['dashboard.spread', number_format((float) $d['spread'], 0, '.', ' ') . ' Lek'];
        }
    @endphp

    <div class="row g-3 mb-3">
        @foreach ($tiles as [$label, $value])
            <div class="col-6 col-lg-3">
                <div class="card"><div class="card-body">
                    <div class="text-muted small">{{ trans('plugins/emiron-fx::fx.' . $label) }}</div>
                    <div class="fs-2 fw-bold font-monospace">{{ $value }}</div>
                </div></div>
            </div>
        @endforeach
    </div>

    {{-- 0.7.0: fitimi / humbja reale (kosto mesatare e ponderuar) — 0.7.1: vetëm me fx.pnl.index --}}
    @if ($canPnl)
    <div class="card mb-3">
        <div class="card-header"><h4 class="card-title">{{ trans('plugins/emiron-fx::fx.pnl.title') }}</h4></div>
        <div class="table-responsive">
            <table class="table card-table table-sm table-vcenter">
                <thead><tr><th></th>
                    <th class="text-end">{{ trans('plugins/emiron-fx::fx.pnl.stock') }}</th><th class="text-end">{{ trans('plugins/emiron-fx::fx.pnl.avg') }}</th>
                    <th class="text-end">{{ trans('plugins/emiron-fx::fx.pnl.realized_day') }}</th><th class="text-end">{{ trans('plugins/emiron-fx::fx.pnl.realized_month') }}</th>
                    <th class="text-end">{{ trans('plugins/emiron-fx::fx.pnl.variance_month') }}</th><th class="text-end">{{ trans('plugins/emiron-fx::fx.pnl.unrealized') }}</th></tr></thead>
                @foreach ($pnlMonth['rows'] as $code => $row)
                    <tr>
                        <td><strong>{{ $code }}</strong></td>
                        <td class="text-end font-monospace">{{ number_format((float) $row['qty'], 2, '.', ' ') }}</td>
                        <td class="text-end font-monospace text-muted">{{ fx_rate($row['avg']) }}</td>
                        <td class="text-end font-monospace">{{ number_format((float) ($pnlDay['rows'][$code]['realized'] ?? 0), 0, '.', ' ') }}</td>
                        <td class="text-end font-monospace">{{ number_format((float) $row['realized'], 0, '.', ' ') }}</td>
                        <td class="text-end font-monospace {{ (float) $row['variance'] < 0 ? 'text-danger' : '' }}">{{ number_format((float) $row['variance'], 0, '.', ' ') }}</td>
                        <td class="text-end font-monospace {{ (float) $row['unrealized'] < 0 ? 'text-danger' : 'text-success' }}">{{ number_format((float) $row['unrealized'], 0, '.', ' ') }}</td>
                    </tr>
                @endforeach
                <tr class="fw-bold">
                    <td>{{ trans('plugins/emiron-fx::fx.pnl.total') }}</td><td></td><td></td>
                    <td class="text-end font-monospace">{{ number_format((float) $pnlDay['totals']['net'], 0, '.', ' ') }}</td>
                    <td class="text-end font-monospace">{{ number_format((float) $pnlMonth['totals']['realized'], 0, '.', ' ') }}</td>
                    <td class="text-end font-monospace">{{ number_format((float) $pnlMonth['totals']['variance'], 0, '.', ' ') }}</td>
                    <td class="text-end font-monospace">{{ number_format((float) $pnlMonth['totals']['unrealized'], 0, '.', ' ') }}</td>
                </tr>
            </table>
        </div>
        <div class="card-footer small text-muted">
            {{ trans('plugins/emiron-fx::fx.pnl.net_month', ['net' => number_format((float) $pnlMonth['totals']['net'], 0, '.', ' ')]) }} ·
            {{ trans('plugins/emiron-fx::fx.pnl.note') }}
        </div>
    </div>
    @endif

    @if ($canBank)
    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h4 class="card-title">{{ trans('plugins/emiron-fx::fx.pnl.liquidity') }}</h4></div>
                <table class="table card-table table-sm">
                    <thead><tr><th></th><th class="text-end">{{ trans('plugins/emiron-fx::fx.tills.title') }}</th><th class="text-end">{{ trans('plugins/emiron-fx::fx.bank.title') }}</th><th class="text-end">{{ trans('plugins/emiron-fx::fx.pnl.total') }}</th></tr></thead>
                    @foreach ($liquidity as $code => $l)
                        <tr><td><strong>{{ $code }}</strong></td>
                            <td class="text-end font-monospace">{{ number_format((float) ($l['tills'] ?? 0), 2, '.', ' ') }}</td>
                            <td class="text-end font-monospace">{{ number_format((float) ($l['bank'] ?? 0), 2, '.', ' ') }}</td>
                            <td class="text-end font-monospace fw-bold">{{ number_format((float) ($l['tills'] ?? 0) + (float) ($l['bank'] ?? 0), 2, '.', ' ') }}</td></tr>
                    @endforeach
                </table>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h4 class="card-title">{{ trans('plugins/emiron-fx::fx.partners.title') }}</h4></div>
                <table class="table card-table table-sm">
                    @foreach ($partners as $p)
                        @foreach ($p['balances'] as $code => $b)
                            <tr><td>{{ $p['partner']->name }}</td><td>{{ $code }}</td>
                                <td class="text-end font-monospace small">{{ trans('plugins/emiron-fx::fx.partners.prepaid') }} {{ number_format((float) $b['prepaid'], 2, '.', ' ') }}</td>
                                <td class="text-end font-monospace small">{{ trans('plugins/emiron-fx::fx.partners.receivable') }} {{ number_format((float) $b['receivable'], 2, '.', ' ') }}</td></tr>
                        @endforeach
                    @endforeach
                </table>
                <div class="card-footer"><a href="{{ route('fx.recon.index') }}">{{ trans('plugins/emiron-fx::fx.recon.title') }} →</a></div>
            </div>
        </div>
    </div>
    @endif

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><h4 class="card-title">{{ trans('plugins/emiron-fx::fx.dashboard.by_currency') }}</h4></div>
                <div class="table-responsive">
                    <table class="table card-table table-sm table-vcenter">
                        <thead><tr><th></th><th class="text-end">{{ trans('plugins/emiron-fx::fx.reports.bought') }}</th><th class="text-end">Lek</th><th class="text-end">{{ trans('plugins/emiron-fx::fx.reports.sold') }}</th><th class="text-end">Lek</th>@if ($canPnl)<th class="text-end">{{ trans('plugins/emiron-fx::fx.dashboard.spread_short') }}</th>@endif</tr></thead>
                        @forelse ($d['currencies'] as $code => $c)
                            <tr>
                                <td><strong>{{ $code }}</strong> <span class="text-muted small">({{ $c['count'] }})</span></td>
                                <td class="text-end font-monospace">{{ number_format((float) $c['bought'], 2, '.', ' ') }}</td>
                                <td class="text-end font-monospace text-muted">{{ number_format((float) $c['bought_lek'], 0, '.', ' ') }}</td>
                                <td class="text-end font-monospace">{{ number_format((float) $c['sold'], 2, '.', ' ') }}</td>
                                <td class="text-end font-monospace text-muted">{{ number_format((float) $c['sold_lek'], 0, '.', ' ') }}</td>
                                @if ($canPnl)<td class="text-end font-monospace">{{ number_format((float) $c['spread'], 0, '.', ' ') }}</td>@endif
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-muted">{{ trans('plugins/emiron-fx::fx.reports.empty') }}</td></tr>
                        @endforelse
                    </table>
                </div>
                <div class="card-footer text-muted small">
                    @if ($canPnl){{ trans('plugins/emiron-fx::fx.dashboard.spread_note') }}@endif
                    @if ($d['voids']) · {{ trans('plugins/emiron-fx::fx.dashboard.voids', ['count' => $d['voids']]) }} @endif
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            @if ($canTills)
            <div class="card mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <strong>{{ trans('plugins/emiron-fx::fx.counter.holding') }}</strong>
                        <span class="font-monospace">{{ number_format((float) $d['holding']['before'], 0, '.', ' ') }} / {{ number_format((float) $d['holding']['limit'], 0, '.', ' ') }}</span>
                    </div>
                    <div class="progress mt-2"><div class="progress-bar {{ $d['holding']['exceeds'] ? 'bg-danger' : ($d['holding']['warn'] ? 'bg-warning' : 'bg-success') }}" style="width: {{ min(100, $d['holding']['percent']) }}%"></div></div>
                    <div class="small text-muted mt-2">{{ trans('plugins/emiron-fx::fx.dashboard.last_publish') }}: {{ $d['lastPublish'] ? BaseHelper::formatDateTime($d['lastPublish']) : '—' }}</div>
                </div>
            </div>
            @endif
            @foreach ($d['tills'] as $t)
                <div class="card mb-2">
                    <div class="card-body py-2">
                        <div class="d-flex justify-content-between">
                            <strong>{{ $t['till']->name }}</strong>
                            <span class="small">
                                {{ $t['till']->cashier?->name ?? '—' }} ·
                                @if ($t['session'])
                                    @if ($canTills)
                                        <a href="{{ route('fx.sessions.show', $t['session']->id) }}">{{ trans('plugins/emiron-fx::fx.sessions.status_' . $t['session']->status) }}</a>
                                    @else
                                        {{ trans('plugins/emiron-fx::fx.sessions.status_' . $t['session']->status) }}
                                    @endif
                                @else
                                    {{ trans('plugins/emiron-fx::fx.dashboard.till_closed') }}
                                @endif
                            </span>
                        </div>
                        <div class="small font-monospace text-muted">
                            @forelse ($t['balances'] as $code => $amount)
                                {{ $code }} {{ number_format((float) $amount, 2, '.', ' ') }}@if (! $loop->last) · @endif
                            @empty
                                —
                            @endforelse
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endsection
