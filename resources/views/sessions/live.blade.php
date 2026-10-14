@extends(BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    <div class="card">
        <div class="card-header">
            <div>
                <h4 class="card-title">{{ trans('plugins/emiron-fx::fx.sessions.live_title') }}</h4>
                <div class="text-muted small">{{ trans('plugins/emiron-fx::fx.sessions.live_help') }}</div>
            </div>
        </div>

        @if (empty($rows))
            <div class="card-body text-muted">{{ trans('plugins/emiron-fx::fx.sessions.live_empty') }}</div>
        @else
            <div class="table-responsive">
                <table class="table table-vcenter card-table table-sm">
                    <thead>
                        <tr>
                            <th>{{ trans('plugins/emiron-fx::fx.sessions.till') }}</th>
                            <th>{{ trans('plugins/emiron-fx::fx.sessions.cashier') }}</th>
                            <th>{{ trans('plugins/emiron-fx::fx.sessions.live_open_since') }}</th>
                            @foreach ($codes as $code)
                                <th class="text-end">{{ $code }}</th>
                            @endforeach
                            <th class="text-end">{{ trans('plugins/emiron-fx::fx.sessions.live_value') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php $s = $row['session']; @endphp
                            <tr>
                                <td>
                                    <a href="{{ route('fx.sessions.show', $s->id) }}">{{ $s->till?->name ?? '—' }}</a>
                                    <div class="small text-muted">{{ $s->till?->office?->name }}</div>
                                </td>
                                <td>{{ $s->cashier?->name ?? '—' }}</td>
                                <td class="small">{{ BaseHelper::formatDateTime($s->opened_at) }}</td>
                                @foreach ($codes as $code)
                                    @php $amount = (float) ($row['balances'][$code] ?? 0); @endphp
                                    <td class="text-end font-monospace {{ $amount < 0 ? 'text-danger' : '' }}">{{ number_format($amount, 2, '.', ' ') }}</td>
                                @endforeach
                                <td class="text-end font-monospace">{{ number_format((float) $row['value'], 0, '.', ' ') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="fw-bold">
                            <td colspan="3">{{ trans('plugins/emiron-fx::fx.sessions.live_total') }}</td>
                            @foreach ($codes as $code)
                                <td class="text-end font-monospace">{{ number_format((float) ($totals[$code] ?? 0), 2, '.', ' ') }}</td>
                            @endforeach
                            <td class="text-end font-monospace">{{ number_format((float) $totalValue, 0, '.', ' ') }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </div>
@endsection
