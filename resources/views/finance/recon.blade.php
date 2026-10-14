@extends(BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    @php
        $allClear = $r['cashOut']->isEmpty() && $r['cashIn']->isEmpty() && $r['pendingBank']->isEmpty()
            && $r['partners']->every(fn ($p) => $p['aging']->isEmpty()) && $r['lowPrepaid']->isEmpty();
    @endphp

    <div class="alert {{ $allClear ? 'alert-success' : 'alert-warning' }}">
        {{ $allClear ? trans('plugins/emiron-fx::fx.recon.clear') : trans('plugins/emiron-fx::fx.recon.open') }}
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card mb-3">
                <div class="card-header"><h4 class="card-title">1 · {{ trans('plugins/emiron-fx::fx.recon.cash_out') }} ({{ $r['cashOut']->count() }})</h4></div>
                <table class="table card-table table-sm">
                    @forelse ($r['cashOut'] as $m)
                        <tr><td class="small">{{ $m->created_at?->format('d.m.Y H:i') }}</td><td class="font-monospace text-end">{{ number_format(abs((float) $m->amount), 2, '.', ' ') }} {{ $m->currency_code }}</td><td class="small text-muted">{{ $m->note }}</td></tr>
                    @empty
                        <tr><td class="text-success small">✓</td></tr>
                    @endforelse
                </table>
                <div class="card-footer small text-muted">{{ trans('plugins/emiron-fx::fx.recon.cash_out_help') }}</div>
            </div>
            <div class="card mb-3">
                <div class="card-header"><h4 class="card-title">2 · {{ trans('plugins/emiron-fx::fx.recon.cash_in') }} ({{ $r['cashIn']->count() }})</h4></div>
                <table class="table card-table table-sm">
                    @forelse ($r['cashIn'] as $m)
                        <tr><td class="small">{{ $m->created_at?->format('d.m.Y H:i') }}</td><td class="font-monospace text-end">{{ number_format((float) $m->amount, 2, '.', ' ') }} {{ $m->currency_code }}</td><td class="small text-muted">{{ $m->note }}</td></tr>
                    @empty
                        <tr><td class="text-success small">✓</td></tr>
                    @endforelse
                </table>
            </div>
            <div class="card">
                <div class="card-header"><h4 class="card-title">3 · {{ trans('plugins/emiron-fx::fx.recon.pending_bank') }} ({{ $r['pendingBank']->count() }})</h4></div>
                <table class="table card-table table-sm">
                    @forelse ($r['pendingBank'] as $e)
                        <tr><td class="small">{{ $e->entry_date->format('d.m.Y') }}</td><td class="small">{{ trans('plugins/emiron-fx::fx.bank.types.' . $e->type) }} @if ($e->partner)· {{ $e->partner->name }}@endif</td><td class="font-monospace text-end">{{ number_format((float) $e->amount, 2, '.', ' ') }} {{ $e->account?->currency_code }}</td></tr>
                    @empty
                        <tr><td class="text-success small">✓</td></tr>
                    @endforelse
                </table>
                <div class="card-footer"><a href="{{ route('fx.bank.index') }}">{{ trans('plugins/emiron-fx::fx.bank.title') }} →</a></div>
            </div>
        </div>
        <div class="col-lg-6">
            @foreach ($r['partners'] as $p)
                <div class="card mb-3">
                    <div class="card-header"><h4 class="card-title">4 · {{ $p['partner']->name }}</h4></div>
                    <div class="card-body">
                        @foreach ($p['balances'] as $code => $b)
                            <div class="d-flex justify-content-between small font-monospace">
                                <span>{{ $code }}</span>
                                <span>{{ trans('plugins/emiron-fx::fx.partners.prepaid') }} {{ number_format((float) $b['prepaid'], 2, '.', ' ') }}</span>
                                <span class="{{ (float) $b['receivable'] > 0 ? 'text-warning' : ((float) $b['receivable'] < 0 ? 'text-danger' : '') }}">{{ trans('plugins/emiron-fx::fx.partners.receivable') }} {{ number_format((float) $b['receivable'], 2, '.', ' ') }}</span>
                            </div>
                        @endforeach
                        @if ($p['aging']->isNotEmpty())
                            <hr>
                            <div class="small fw-bold">{{ trans('plugins/emiron-fx::fx.recon.unreimbursed') }}</div>
                            @foreach ($p['aging'] as $item)
                                <div class="small {{ $item['overdue'] ? 'text-danger fw-bold' : '' }}">{{ $item['date']->format('d.m.Y') }} · {{ number_format((float) $item['open'], 2, '.', ' ') }} {{ $item['currency'] }} @if ($item['overdue'])· {{ trans('plugins/emiron-fx::fx.recon.overdue') }}@endif</div>
                            @endforeach
                        @endif
                    </div>
                </div>
            @endforeach
            @if ($r['lowPrepaid']->isNotEmpty())
                <div class="alert alert-danger">
                    @foreach ($r['lowPrepaid'] as $low)
                        <div>5 · {{ trans('plugins/emiron-fx::fx.alerts.prepaid_low_intro', ['partner' => $low['partner']->name, 'balance' => $low['balance'], 'limit' => (string) $low['partner']->prepaid_low_alert]) }}</div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
