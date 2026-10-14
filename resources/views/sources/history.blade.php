@extends(BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    @php $statusBadge = ['ok' => 'success', 'partial' => 'warning', 'failed' => 'danger', 'skipped' => 'secondary']; @endphp

    <div class="card">
        <div class="card-header">
            <h4 class="card-title">{{ $source['name'] }}</h4>
            <div class="card-actions"><a href="{{ route('fx.sources.index') }}" class="btn btn-sm btn-outline-secondary">← {{ trans('plugins/emiron-fx::fx.sources.title') }}</a></div>
        </div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table table-sm">
                <thead>
                    <tr>
                        <th>{{ trans('plugins/emiron-fx::fx.sources.when') }}</th>
                        <th>{{ trans('plugins/emiron-fx::fx.sources.col_status') }}</th>
                        <th>{{ trans('plugins/emiron-fx::fx.sources.trigger') }}</th>
                        <th>{{ trans('plugins/emiron-fx::fx.sources.data_date') }}</th>
                        <th class="text-end">{{ trans('plugins/emiron-fx::fx.sources.rows_read') }}</th>
                        <th class="text-end">{{ trans('plugins/emiron-fx::fx.sources.rows_saved') }}</th>
                        <th class="text-end">{{ trans('plugins/emiron-fx::fx.sources.rows_unchanged') }}</th>
                        <th class="text-end">{{ trans('plugins/emiron-fx::fx.sources.rows_skipped') }}</th>
                        <th>{{ trans('plugins/emiron-fx::fx.sources.detail') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($runs as $run)
                        <tr>
                            <td class="small">{{ BaseHelper::formatDateTime($run->finished_at) }}</td>
                            <td>
                                <span class="badge text-bg-{{ $statusBadge[$run->status] ?? 'secondary' }}">{{ trans('plugins/emiron-fx::fx.sources.status_' . $run->status) }}</span>
                                @if ($run->dry_run)<span class="badge text-bg-info">{{ trans('plugins/emiron-fx::fx.sources.dry_badge') }}</span>@endif
                            </td>
                            <td class="small">{{ trans('plugins/emiron-fx::fx.sources.trigger_' . $run->triggered_by) }}</td>
                            <td class="small">{{ $run->data_date?->format('d.m.Y') ?? '—' }}</td>
                            <td class="text-end">{{ $run->rows_read }}</td>
                            <td class="text-end">{{ $run->rows_saved }}</td>
                            <td class="text-end">{{ $run->rows_unchanged }}</td>
                            <td class="text-end">{{ $run->rows_skipped }}</td>
                            <td class="small">
                                @if ($run->error)<div class="text-danger text-break">{{ $run->error }}</div>@endif
                                @foreach ((array) ($run->summary['skipped'] ?? []) as $line)<div class="text-warning">{{ $line }}</div>@endforeach
                                @foreach ((array) ($run->summary['errors'] ?? []) as $line)<div class="text-warning">{{ $line }}</div>@endforeach
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-muted">{{ trans('plugins/emiron-fx::fx.sources.no_runs') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
