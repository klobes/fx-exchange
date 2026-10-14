@extends(BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <strong>{{ trans('plugins/emiron-fx::fx.reference.title') }}</strong>
                <div class="text-muted small">{{ trans('plugins/emiron-fx::fx.reference.help', ['limit' => $limit]) }}</div>
                <div class="text-muted small">{{ trans('plugins/emiron-fx::fx.reference.auto', ['times' => implode(', ', $times)]) }}</div>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('fx.reference.history') }}" class="btn btn-outline-secondary">{{ trans('plugins/emiron-fx::fx.reference.history') }}</a>
                @if ($canImport)
                    <form method="POST" action="{{ route('fx.reference.import') }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-primary">{{ trans('plugins/emiron-fx::fx.reference.import_now') }}</button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th>{{ trans('plugins/emiron-fx::fx.currencies.code') }}</th>
                        <th class="text-end">{{ trans('plugins/emiron-fx::fx.rates.official') }}</th>
                        @foreach ($sources as $label)
                            <th class="text-end">{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($currencies as $currency)
                        @php $off = $official->get($currency->id); @endphp
                        <tr>
                            <td>
                                <strong>{{ $currency->unit }} {{ $currency->code }}</strong>
                                <div class="text-muted small">{{ $currency->name }}</div>
                            </td>
                            <td class="text-end">
                                @if ($off)
                                    {{ fx_rate($off->rate) }}
                                    <div class="small text-muted">{{ $off->rate_date->format('d.m.Y') }}</div>
                                @else
                                    <span class="text-warning small">{{ trans('plugins/emiron-fx::fx.rates.no_official') }}</span>
                                @endif
                            </td>
                            @foreach ($sources as $source => $label)
                                @php $ref = $latest->get($source)?->get($currency->id); @endphp
                                <td class="text-end">
                                    @if ($ref)
                                        {{ fx_rate($ref->rate) }}
                                        @if ($off)
                                            @php $div = (float) $rateService->deviationPercent((string) $ref->rate, (string) $off->rate); @endphp
                                            <span class="small {{ abs($div) > $limit ? 'text-danger fw-bold' : 'text-muted' }}">({{ sprintf('%+.2f', $div) }}%)</span>
                                        @endif
                                        <div class="small text-muted" title="{{ $ref->basis }}">{{ $ref->rate_date->format('d.m.Y') }}</div>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ 2 + $sources->count() }}" class="text-muted">{{ trans('plugins/emiron-fx::fx.rates.no_active') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer text-muted small">{{ trans('plugins/emiron-fx::fx.reference.footer') }}</div>
    </div>

    <div class="card mt-3">
        <div class="card-header"><h4 class="card-title">{{ trans('plugins/emiron-fx::fx.reference.sources_title') }}</h4></div>
        <div class="card-body text-muted small pb-0">{{ trans('plugins/emiron-fx::fx.reference.sources_help') }}</div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th>{{ trans('plugins/emiron-fx::fx.reference.src_name') }}</th>
                        <th>{{ trans('plugins/emiron-fx::fx.reference.src_status') }}</th>
                        <th>{{ trans('plugins/emiron-fx::fx.reference.src_how') }}</th>
                        <th>{{ trans('plugins/emiron-fx::fx.reference.src_url') }}</th>
                        <th>{{ trans('plugins/emiron-fx::fx.reference.src_last_saved') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sourceInfo as $key => $info)
                        <tr>
                            <td><strong>{{ $info['label'] }}</strong> <span class="text-muted small">({{ $key }})</span></td>
                            <td>
                                @if ($info['enabled'])
                                    <span class="badge bg-success text-white">{{ trans('plugins/emiron-fx::fx.reference.src_enabled') }}</span>
                                @else
                                    <span class="badge bg-secondary text-white">{{ trans('plugins/emiron-fx::fx.reference.src_disabled') }}</span>
                                @endif
                            </td>
                            <td class="small">{{ trans('plugins/emiron-fx::fx.reference.src_desc.' . $key) }}</td>
                            <td class="small text-break font-monospace">{{ $info['url'] }}</td>
                            <td class="small">{{ $info['last_saved'] ? \Carbon\Carbon::parse($info['last_saved'])->format('d.m.Y H:i') : trans('plugins/emiron-fx::fx.reference.src_never') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
