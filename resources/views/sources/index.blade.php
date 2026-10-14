@extends(BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    @php
        $healthBadge = ['ok' => 'success', 'warn' => 'warning', 'bad' => 'danger', 'never' => 'secondary', 'off' => 'secondary'];
        $statusBadge = ['ok' => 'success', 'partial' => 'warning', 'failed' => 'danger', 'skipped' => 'secondary'];
    @endphp

    <div class="alert alert-info">
        <strong>{{ trans('plugins/emiron-fx::fx.sources.help_title') }}</strong>
        <div class="small">{{ trans('plugins/emiron-fx::fx.sources.help') }}</div>
    </div>

    @if ($result)
        <div class="card mb-3">
            <div class="card-header">
                <h4 class="card-title">
                    {{ trans('plugins/emiron-fx::fx.sources.result_title', ['source' => $result['label']]) }}
                    <span class="badge text-bg-{{ $statusBadge[$result['status']] ?? 'secondary' }}">{{ trans('plugins/emiron-fx::fx.sources.status_' . $result['status']) }}</span>
                    @if ($result['dry_run'])<span class="badge text-bg-info">{{ trans('plugins/emiron-fx::fx.sources.dry_badge') }}</span>@endif
                </h4>
                <div class="card-actions small text-muted">
                    {{ trans('plugins/emiron-fx::fx.sources.data_date') }}: {{ $result['data_date'] ?? '—' }}
                    · {{ trans('plugins/emiron-fx::fx.sources.rows_read') }}: {{ $result['read'] }}
                    @unless ($result['dry_run'])· {{ trans('plugins/emiron-fx::fx.sources.rows_saved') }}: {{ $result['saved'] }} · {{ trans('plugins/emiron-fx::fx.sources.rows_unchanged') }}: {{ $result['unchanged'] }}@endunless
                </div>
            </div>
            @if ($result['error'])
                <div class="card-body text-danger">{{ $result['error'] }}</div>
            @endif
            @if ($result['rows'])
                <div class="table-responsive">
                    <table class="table card-table table-sm">
                        <thead><tr>
                            <th>{{ trans('plugins/emiron-fx::fx.currencies.code') }}</th>
                            <th class="text-end">{{ trans('plugins/emiron-fx::fx.sources.rate_per_unit') }}</th>
                            <th class="text-end">{{ trans('plugins/emiron-fx::fx.rates.official') }}</th>
                            <th class="text-end">%</th>
                            <th></th>
                        </tr></thead>
                        @foreach ($result['rows'] as $code => $row)
                            <tr>
                                <td><strong>{{ $code }}</strong></td>
                                <td class="text-end font-monospace">{{ number_format((float) $row['rate'], 4, '.', ' ') }}</td>
                                <td class="text-end font-monospace text-muted">{{ $row['official'] !== null ? number_format((float) $row['official'], 4, '.', ' ') : '—' }}</td>
                                <td class="text-end font-monospace {{ ! empty($row['skipped']) ? 'text-danger fw-bold' : 'text-muted' }}">{{ $row['deviation'] !== null ? sprintf('%+.2f', $row['deviation']) : '—' }}</td>
                                <td>@if (! empty($row['skipped']))<span class="badge text-bg-danger">{{ trans('plugins/emiron-fx::fx.sources.skipped_badge') }}</span>@endif</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            @endif
            @foreach (array_merge($result['errors'], $result['skipped']) as $line)
                <div class="card-footer small text-warning">{{ $line }}</div>
            @endforeach
        </div>
    @endif

    <div class="card">
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th>{{ trans('plugins/emiron-fx::fx.sources.col_source') }}</th>
                        <th>{{ trans('plugins/emiron-fx::fx.sources.col_status') }}</th>
                        <th>{{ trans('plugins/emiron-fx::fx.sources.col_last_attempt') }}</th>
                        <th>{{ trans('plugins/emiron-fx::fx.sources.col_last_success') }}</th>
                        <th>{{ trans('plugins/emiron-fx::fx.sources.col_schedule') }}</th>
                        <th>{{ trans('plugins/emiron-fx::fx.sources.avg_col') }}</th>
                        <th class="text-end">{{ trans('plugins/emiron-fx::fx.sources.col_actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sources as $s)
                        <tr>
                            <td style="max-width:340px">
                                <strong>{{ $s['name'] }}</strong>
                                <span class="badge text-bg-{{ $s['role'] === 'official' ? 'primary' : ($s['role'] === 'official_mirror' ? 'info' : 'secondary') }}">{{ trans('plugins/emiron-fx::fx.sources.role_' . $s['role']) }}</span>
                                <div class="small text-muted">{{ $s['description'] }}</div>
                                <div class="small text-muted font-monospace text-break">{{ $s['url'] }}</div>
                                @if ($s['attribution'])<div class="small text-muted">{{ trans('plugins/emiron-fx::fx.sources.attribution') }}: {{ $s['attribution'] }}</div>@endif
                                @if (! empty($s['needs_secret']))
                                    <div class="small mt-1">
                                        @if ($s['has_secret'])
                                            <span class="badge text-bg-success">{{ trans('plugins/emiron-fx::fx.sources.secret_set') }}</span>
                                        @else
                                            <span class="badge text-bg-danger">{{ trans('plugins/emiron-fx::fx.sources.secret_missing') }}</span>
                                        @endif
                                    </div>
                                    @if ($canManage)
                                        <form method="POST" action="{{ route('fx.sources.secret', $s['code']) }}" class="d-flex gap-1 mt-1" autocomplete="off">
                                            @csrf
                                            <input type="password" name="token" class="form-control form-control-sm" autocomplete="new-password"
                                                   placeholder="{{ trans($s['has_secret'] ? 'plugins/emiron-fx::fx.sources.secret_replace' : 'plugins/emiron-fx::fx.sources.secret_enter') }}">
                                            <button class="btn btn-sm btn-outline-primary">{{ trans('plugins/emiron-fx::fx.sources.save') }}</button>
                                        </form>
                                        @if ($s['has_secret'])
                                            <form method="POST" action="{{ route('fx.sources.secret', $s['code']) }}" class="mt-1">
                                                @csrf
                                                <input type="hidden" name="clear" value="1">
                                                <button class="btn btn-sm btn-link text-danger p-0">{{ trans('plugins/emiron-fx::fx.sources.secret_clear') }}</button>
                                            </form>
                                        @endif
                                        <div class="small text-muted">{{ trans('plugins/emiron-fx::fx.sources.secret_help') }}</div>
                                    @endif
                                @endif
                            </td>
                            <td><span class="badge text-bg-{{ $healthBadge[$s['health']] ?? 'secondary' }}">{{ trans('plugins/emiron-fx::fx.sources.health_' . $s['health']) }}</span></td>
                            <td class="small">
                                @if ($s['last'])
                                    {{ BaseHelper::formatDateTime($s['last']->finished_at) }}
                                    <span class="badge text-bg-{{ $statusBadge[$s['last']->status] ?? 'secondary' }}">{{ trans('plugins/emiron-fx::fx.sources.status_' . $s['last']->status) }}</span>
                                    @if ($s['last']->error)<div class="text-danger text-break">{{ \Illuminate\Support\Str::limit($s['last']->error, 140) }}</div>@endif
                                @else
                                    <span class="text-muted">{{ trans('plugins/emiron-fx::fx.sources.never') }}</span>
                                @endif
                            </td>
                            <td class="small">
                                @if ($s['success'])
                                    {{ BaseHelper::formatDateTime($s['success']->finished_at) }}
                                    <div class="text-muted">
                                        {{ trans('plugins/emiron-fx::fx.sources.data_date') }}: {{ $s['success']->data_date?->format('d.m.Y') ?? '—' }}
                                        · {{ $s['success']->rows_read }} / {{ $s['success']->rows_saved }} / {{ $s['success']->rows_unchanged }}
                                    </div>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="small text-muted">{{ implode(', ', array_filter((array) $s['times'])) ?: '—' }}</td>
                            <td class="small">
                                @if ($s['role'] === 'official')
                                    <span class="text-muted">—</span>
                                @else
                                    <span class="badge text-bg-{{ $s['use_in_average'] ? 'success' : 'secondary' }}">{{ trans($s['use_in_average'] ? 'plugins/emiron-fx::fx.sources.avg_in' : 'plugins/emiron-fx::fx.sources.avg_out') }}</span>
                                    @if ($canManage)
                                        <form method="POST" action="{{ route('fx.sources.average', $s['code']) }}" class="d-inline">
                                            @csrf
                                            <button class="btn btn-sm btn-link p-0">{{ trans($s['use_in_average'] ? 'plugins/emiron-fx::fx.sources.avg_exclude' : 'plugins/emiron-fx::fx.sources.avg_include') }}</button>
                                        </form>
                                    @endif
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                <form method="POST" action="{{ route('fx.sources.run', $s['code']) }}" class="d-inline">
                                    @csrf
                                    <input type="hidden" name="mode" value="dry">
                                    <button class="btn btn-sm btn-outline-secondary">{{ trans('plugins/emiron-fx::fx.sources.action_dry') }}</button>
                                </form>
                                @if ($canManage)
                                    <form method="POST" action="{{ route('fx.sources.run', $s['code']) }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="mode" value="import">
                                        <button class="btn btn-sm btn-outline-primary">{{ trans('plugins/emiron-fx::fx.sources.action_import') }}</button>
                                    </form>
                                    @if ($s['toggleable'])
                                        <form method="POST" action="{{ route('fx.sources.toggle', $s['code']) }}" class="d-inline">
                                            @csrf
                                            <button class="btn btn-sm {{ $s['enabled'] ? 'btn-outline-danger' : 'btn-outline-success' }}">
                                                {{ trans($s['enabled'] ? 'plugins/emiron-fx::fx.sources.action_disable' : 'plugins/emiron-fx::fx.sources.action_enable') }}
                                            </button>
                                        </form>
                                    @endif
                                @endif
                                <a href="{{ route('fx.sources.history', $s['code']) }}" class="btn btn-sm btn-outline-secondary">{{ trans('plugins/emiron-fx::fx.sources.history') }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-muted">
            {{ trans('plugins/emiron-fx::fx.sources.footer') }}
            <a href="{{ route('fx.reference.index') }}">{{ trans('plugins/emiron-fx::fx.reference.title') }} →</a>
        </div>
    </div>

    {{-- 0.8.2: kursi i tregut (MID) dhe rekomandimi --}}
    <div class="card mt-3">
        <div class="card-header"><h4 class="card-title">{{ trans('plugins/emiron-fx::fx.sources.market_title') }}</h4></div>
        <div class="card-body small text-muted pb-0">{{ trans('plugins/emiron-fx::fx.sources.market_help') }}</div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table table-sm">
                <thead>
                    <tr>
                        <th>{{ trans('plugins/emiron-fx::fx.sources.market_currency') }}</th>
                        <th class="text-end">{{ trans('plugins/emiron-fx::fx.sources.market_mid') }}</th>
                        <th>{{ trans('plugins/emiron-fx::fx.sources.market_sources') }}</th>
                        <th class="text-end">{{ trans('plugins/emiron-fx::fx.sources.market_vs_boa') }}</th>
                        <th class="text-end">{{ trans('plugins/emiron-fx::fx.sources.market_office') }}</th>
                        <th class="text-end">{{ trans('plugins/emiron-fx::fx.sources.market_drift') }}</th>
                        <th class="text-end">{{ trans('plugins/emiron-fx::fx.sources.market_recommended') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($market as $row)
                        @php $c = $row['consensus']; @endphp
                        <tr>
                            <td><strong>{{ $row['currency']->code }}</strong></td>
                            <td class="text-end font-monospace">{{ ($c['mid'] ?? null) !== null ? fx_rate($c['mid']) : '—' }}</td>
                            <td class="small">
                                @if (($c['mid'] ?? null) !== null)
                                    <span class="badge text-bg-info">{{ trans('plugins/emiron-fx::fx.sources.method_' . $c['method']) }} · {{ $c['count'] }}</span>
                                    @foreach ($c['sources'] as $code => $value)
                                        <span class="text-muted">{{ $code }} {{ fx_rate($value) }}</span>@if (! $loop->last) · @endif
                                    @endforeach
                                @else
                                    <span class="text-warning">{{ trans('plugins/emiron-fx::fx.sources.reason_' . ($c['reason'] ?? 'no_data')) }}</span>
                                @endif
                                @foreach (($c['excluded'] ?? []) as $code => $why)
                                    <div class="text-danger">{{ $code }}: {{ $why }}</div>
                                @endforeach
                            </td>
                            <td class="text-end font-monospace {{ isset($c['deviation']) && $c['deviation'] !== null && abs($c['deviation']) > (float) config('plugins.emiron-fx.general.reference_divergence_alert_percent', 1.5) ? 'text-danger fw-bold' : 'text-muted' }}">
                                {{ isset($c['deviation']) && $c['deviation'] !== null ? sprintf('%+.2f%%', $c['deviation']) : '—' }}
                            </td>
                            <td class="text-end font-monospace">
                                @if ($row['current']) {{ fx_rate($row['current']->buy_rate) }} / {{ fx_rate($row['current']->sell_rate) }} @else — @endif
                            </td>
                            <td class="text-end font-monospace {{ $row['drift'] !== null && abs($row['drift']) >= $marketSettings['drift_threshold'] ? 'text-danger fw-bold' : 'text-muted' }}">
                                {{ $row['drift'] !== null ? sprintf('%+.2f%%', $row['drift']) : '—' }}
                            </td>
                            <td class="text-end font-monospace">
                                @if ($row['recommended']) {{ $row['recommended']['buy'] }} / {{ $row['recommended']['sell'] }} @else — @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if ($canManage)
        <form method="POST" action="{{ route('fx.sources.market') }}" class="card mt-3">
            @csrf
            <div class="card-header"><h4 class="card-title">{{ trans('plugins/emiron-fx::fx.sources.settings_title') }}</h4></div>
            <div class="card-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label" for="fx-margin-mode">{{ trans('plugins/emiron-fx::fx.sources.margin_mode') }}</label>
                        <select name="margin_mode" id="fx-margin-mode" class="form-select">
                            @foreach (\Botble\EmironFx\Services\MarketRateService::MODES as $mode)
                                <option value="{{ $mode }}" @selected($marketSettings['margin_mode'] === $mode)>{{ trans('plugins/emiron-fx::fx.sources.mode_' . $mode) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="fx-drift">{{ trans('plugins/emiron-fx::fx.sources.drift_threshold') }}</label>
                        <input type="text" inputmode="decimal" name="drift_threshold" id="fx-drift" class="form-control" value="{{ $marketSettings['drift_threshold'] }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="fx-min">{{ trans('plugins/emiron-fx::fx.sources.min_sources') }}</label>
                        <input type="number" min="1" max="10" name="min_sources" id="fx-min" class="form-control" value="{{ $marketSettings['min_sources'] }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="fx-def-buy">{{ trans('plugins/emiron-fx::fx.sources.default_buy') }}</label>
                        <input type="text" inputmode="decimal" name="default_buy_lek" id="fx-def-buy" class="form-control text-end" value="{{ old('default_buy_lek', $marketSettings['default_margins'][0]) }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="fx-def-sell">{{ trans('plugins/emiron-fx::fx.sources.default_sell') }}</label>
                        <input type="text" inputmode="decimal" name="default_sell_lek" id="fx-def-sell" class="form-control text-end" value="{{ old('default_sell_lek', $marketSettings['default_margins'][1]) }}">
                    </div>
                    <div class="col-12 form-text mt-0">{{ trans('plugins/emiron-fx::fx.sources.default_margin_help') }}</div>
                    <div class="col-md-6">
                        <label class="form-label" for="fx-loss-mode">{{ trans('plugins/emiron-fx::fx.sources.loss_guard') }}</label>
                        <select name="loss_guard_mode" id="fx-loss-mode" class="form-select">
                            @foreach (\Botble\EmironFx\Services\LossGuardService::MODES as $mode)
                                <option value="{{ $mode }}" @selected($marketSettings['loss_guard_mode'] === $mode)>{{ trans('plugins/emiron-fx::fx.sources.loss_' . $mode) }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">{{ trans('plugins/emiron-fx::fx.sources.loss_guard_help') }}</div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-vcenter">
                        <thead>
                            <tr>
                                <th>{{ trans('plugins/emiron-fx::fx.sources.market_currency') }}</th>
                                <th>{{ trans('plugins/emiron-fx::fx.sources.margin_buy_pct') }}</th>
                                <th>{{ trans('plugins/emiron-fx::fx.sources.margin_sell_pct') }}</th>
                                <th>{{ trans('plugins/emiron-fx::fx.sources.margin_buy_lek') }}</th>
                                <th>{{ trans('plugins/emiron-fx::fx.sources.margin_sell_lek') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($market as $row)
                                @php $m = $marketSettings['margins'][$row['currency']->id] ?? []; $id = $row['currency']->id; @endphp
                                <tr>
                                    <td><strong>{{ $row['currency']->code }}</strong></td>
                                    @foreach (['buy_pct', 'sell_pct', 'buy_lek', 'sell_lek'] as $field)
                                        <td><input type="text" inputmode="decimal" class="form-control form-control-sm text-end" name="margins[{{ $id }}][{{ $field }}]" value="{{ old('margins.' . $id . '.' . $field, $m[$field] ?? '') }}"></td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer text-end"><button class="btn btn-primary">{{ trans('plugins/emiron-fx::fx.sources.save') }}</button></div>
        </form>
    @endif
@endsection
