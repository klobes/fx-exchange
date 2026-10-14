@extends(BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    @if ($preview)
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h4 class="card-title mb-0">{{ trans('plugins/emiron-fx::fx.reports.preview', ['date' => $preview['date']->format('d.m.Y')]) }}</h4>
                <form method="GET" action="{{ route('fx.reports.index') }}" class="d-flex gap-2">
                    <input type="date" name="date" class="form-control" value="{{ $preview['date']->toDateString() }}" max="{{ now()->toDateString() }}">
                    <button class="btn btn-outline-secondary">{{ trans('plugins/emiron-fx::fx.reports.show') }}</button>
                </form>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">{{ trans('plugins/emiron-fx::fx.reports.deadline', ['deadline' => $preview['deadline']->format('d.m.Y H:i')]) }}</p>

                <div class="row g-3">
                    @foreach (['F1.1' => 'f11', 'F1.2' => 'f12'] as $sheet => $key)
                        <div class="col-lg-6">
                            <h5>{{ $sheet }} — {{ trans('plugins/emiron-fx::fx.reports.' . $key) }}</h5>
                            <div class="table-responsive">
                                <table class="table table-sm table-vcenter">
                                    <thead><tr><th>{{ trans('plugins/emiron-fx::fx.reports.col_client') }}</th><th>{{ trans('plugins/emiron-fx::fx.reports.col_type') }}</th><th>{{ trans('plugins/emiron-fx::fx.reports.col_name') }}</th><th></th><th class="text-end">{{ trans('plugins/emiron-fx::fx.reports.col_amount') }}</th><th class="text-end">{{ trans('plugins/emiron-fx::fx.reports.col_rate') }}</th></tr></thead>
                                    @forelse ($preview['boa'][$sheet] as $row)
                                        <tr>
                                            <td class="small">{{ $row['A'] }}</td>
                                            <td class="small">{{ $row['B'] }}</td>
                                            <td class="small">{{ $row['C'] }}</td>
                                            <td class="small"><strong>{{ $row['D'] }}</strong></td>
                                            <td class="text-end font-monospace small">{{ number_format((float) $row['E'], 2, '.', ' ') }}</td>
                                            <td class="text-end font-monospace small">{{ fx_rate($row['F']) }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="6" class="text-muted small">{{ trans('plugins/emiron-fx::fx.reports.empty') }}</td></tr>
                                    @endforelse
                                </table>
                            </div>
                        </div>
                    @endforeach
                    <div class="col-lg-6">
                        <h5>F3 — {{ trans('plugins/emiron-fx::fx.reports.f3') }}</h5>
                        <table class="table table-sm">
                            <thead><tr><th></th><th class="text-end">{{ trans('plugins/emiron-fx::fx.reports.bought') }}</th><th class="text-end">{{ trans('plugins/emiron-fx::fx.reports.sold') }}</th></tr></thead>
                            @forelse ($preview['boa']['F3'] as $row)
                                <tr><td><strong>{{ $row['A'] }}</strong></td><td class="text-end font-monospace small">{{ number_format((float) $row['B'], 2, '.', ' ') }}</td><td class="text-end font-monospace small">{{ number_format((float) $row['C'], 2, '.', ' ') }}</td></tr>
                            @empty
                                <tr><td colspan="3" class="text-muted small">{{ trans('plugins/emiron-fx::fx.reports.empty') }}</td></tr>
                            @endforelse
                        </table>
                    </div>
                    <div class="col-lg-6">
                        <h5>{{ trans('plugins/emiron-fx::fx.reports.aif') }}</h5>
                        @forelse ($preview['aif'] as $row)
                            <div class="border rounded p-2 mb-2 small">
                                <strong>{{ $row['customer']?->displayName() }}</strong> — {{ number_format((float) $row['total'], 0, '.', ' ') }} Lek
                                <div class="text-muted">{{ $row['transactions']->pluck('number')->implode(', ') }}</div>
                            </div>
                        @empty
                            <p class="text-muted small">{{ trans('plugins/emiron-fx::fx.reports.aif_none') }}</p>
                        @endforelse
                    </div>
                </div>
            </div>
            @if ($canGenerate)
                <div class="card-footer d-flex gap-2 justify-content-end flex-wrap">
                    <form method="POST" action="{{ route('fx.reports.generate') }}">
                        @csrf
                        <input type="hidden" name="type" value="boa_daily">
                        <input type="hidden" name="report_date" value="{{ $preview['date']->toDateString() }}">
                        <button class="btn btn-primary">{{ trans('plugins/emiron-fx::fx.reports.generate_boa') }}</button>
                    </form>
                    <form method="POST" action="{{ route('fx.reports.generate') }}">
                        @csrf
                        <input type="hidden" name="type" value="aif_ctr">
                        <input type="hidden" name="report_date" value="{{ $preview['date']->toDateString() }}">
                        <button class="btn btn-outline-danger" @disabled(! $preview['aif'])>{{ trans('plugins/emiron-fx::fx.reports.generate_aif') }}</button>
                    </form>
                </div>
            @endif
        </div>
    @endif

    <div class="card">
        <div class="card-header"><h4 class="card-title">{{ trans('plugins/emiron-fx::fx.reports.register') }}</h4></div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table table-sm">
                <thead>
                    <tr>
                        <th>{{ trans('plugins/emiron-fx::fx.reports.col_date') }}</th>
                        <th>{{ trans('plugins/emiron-fx::fx.reports.col_kind') }}</th>
                        <th>v</th>
                        <th>{{ trans('plugins/emiron-fx::fx.reports.col_generated') }}</th>
                        <th>{{ trans('plugins/emiron-fx::fx.reports.col_submitted') }}</th>
                        <th></th>
                    </tr>
                </thead>
                @forelse ($reports as $report)
                    <tr>
                        <td>{{ $report->report_date->format('d.m.Y') }}</td>
                        <td>{{ trans('plugins/emiron-fx::fx.reports.type_' . $report->type) }}</td>
                        <td>{{ $report->version }}</td>
                        <td class="small">{{ BaseHelper::formatDateTime($report->generated_at) }} · {{ $report->generator?->name }}</td>
                        <td class="small">
                            @if ($report->submitted_at)
                                <span class="badge text-bg-success">{{ BaseHelper::formatDateTime($report->submitted_at) }}</span> {{ $report->submission_ref }}
                            @elseif ($canSubmit)
                                <form method="POST" action="{{ route('fx.reports.submit', $report->id) }}" class="d-flex gap-1">
                                    @csrf
                                    <input type="text" name="submission_ref" class="form-control form-control-sm" placeholder="{{ trans('plugins/emiron-fx::fx.reports.ref_placeholder') }}">
                                    <button class="btn btn-sm btn-outline-success text-nowrap">{{ trans('plugins/emiron-fx::fx.reports.mark_submitted') }}</button>
                                </form>
                            @else
                                <span class="badge text-bg-warning">{{ trans('plugins/emiron-fx::fx.reports.pending') }}</span>
                            @endif
                        </td>
                        <td class="text-end"><a href="{{ route('fx.reports.download', $report->id) }}" class="btn btn-sm btn-outline-primary">{{ trans('plugins/emiron-fx::fx.reports.download') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-muted">{{ trans('plugins/emiron-fx::fx.reports.none') }}</td></tr>
                @endforelse
            </table>
        </div>
        <div class="card-footer text-muted small">{{ trans('plugins/emiron-fx::fx.reports.footer') }}</div>
    </div>

    <form method="GET" action="{{ route('fx.reports.export') }}" class="card mt-3">
        <div class="card-body d-flex flex-wrap gap-2 align-items-center">
            <strong class="me-2">{{ trans('plugins/emiron-fx::fx.export.title') }}</strong>
            <input type="month" name="month" class="form-control" style="max-width:200px" value="{{ now()->subMonth()->format('Y-m') }}" max="{{ now()->format('Y-m') }}">
            <button class="btn btn-outline-primary">{{ trans('plugins/emiron-fx::fx.export.download') }}</button>
            <span class="small text-muted">{{ trans('plugins/emiron-fx::fx.export.help') }}</span>
        </div>
    </form>
@endsection
