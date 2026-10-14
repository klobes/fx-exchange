@extends(BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    <form method="GET" class="d-flex gap-2 mb-3">
        <input type="date" name="date" class="form-control" style="max-width:200px" value="{{ $date->toDateString() }}" max="{{ now()->toDateString() }}">
        <button class="btn btn-outline-secondary">{{ trans('plugins/emiron-fx::fx.reports.show') }}</button>
        <a class="btn btn-outline-primary" href="{{ route('fx.partners.report', ['date' => $date->toDateString(), 'format' => 'csv']) }}">CSV</a>
    </form>

    @forelse ($partners as $p)
        <div class="card mb-3">
            <div class="card-header"><h4 class="card-title">{{ $p['partner']->name }} · {{ $date->format('d.m.Y') }}</h4></div>
            <div class="row g-0">
                <div class="col-lg-7">
                    <table class="table card-table table-sm">
                        <thead><tr><th>{{ trans('plugins/emiron-fx::fx.partner_report.service') }}</th><th></th><th>{{ trans('plugins/emiron-fx::fx.partner_report.currency') }}</th><th class="text-end">{{ trans('plugins/emiron-fx::fx.partner_report.count') }}</th><th class="text-end">{{ trans('plugins/emiron-fx::fx.partner_report.total') }}</th></tr></thead>
                        @forelse ($p['services'] as $s)
                            <tr><td>{{ $s['service'] }}</td><td><span class="badge {{ $s['direction'] === 'out' ? 'text-bg-danger' : 'text-bg-success' }}">{{ strtoupper($s['direction']) }}</span></td><td>{{ $s['currency'] }}</td><td class="text-end">{{ $s['count'] }}</td><td class="text-end font-monospace">{{ number_format((float) $s['total'], 2, '.', ' ') }}</td></tr>
                        @empty
                            <tr><td colspan="5" class="text-muted">{{ trans('plugins/emiron-fx::fx.reports.empty') }}</td></tr>
                        @endforelse
                    </table>
                </div>
                <div class="col-lg-5">
                    <table class="table card-table table-sm">
                        <thead><tr><th></th><th class="text-end">{{ trans('plugins/emiron-fx::fx.partner_report.prepaid_start') }}</th><th class="text-end">{{ trans('plugins/emiron-fx::fx.partner_report.prepaid_end') }}</th><th class="text-end">{{ trans('plugins/emiron-fx::fx.partner_report.receivable_end') }}</th></tr></thead>
                        @foreach ($p['end']->keys()->merge($p['start']->keys())->unique() as $code)
                            <tr><td>{{ $code }}</td>
                                <td class="text-end font-monospace small">{{ number_format((float) ($p['start'][$code]['prepaid'] ?? 0), 2, '.', ' ') }}</td>
                                <td class="text-end font-monospace small">{{ number_format((float) ($p['end'][$code]['prepaid'] ?? 0), 2, '.', ' ') }}</td>
                                <td class="text-end font-monospace small">{{ number_format((float) ($p['end'][$code]['receivable'] ?? 0), 2, '.', ' ') }}</td></tr>
                        @endforeach
                    </table>
                </div>
            </div>
        </div>
    @empty
        <div class="alert alert-info">{{ trans('plugins/emiron-fx::fx.reports.empty') }}</div>
    @endforelse
@endsection
