@extends(BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    <form method="POST" action="{{ route('fx.rates.store') }}" class="fx-publish" data-fx-threshold="{{ $threshold }}">
        @csrf

        <div class="card">
            <div class="card-header">
                <h4 class="card-title">{{ trans('plugins/emiron-fx::fx.rates.publish') }}</h4>
                <div class="card-actions">
                    <a href="{{ route('fx.rates.board') }}" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm">{{ trans('plugins/emiron-fx::fx.counter.print_board') }}</a>
                </div>
            </div>

            <div class="card-body">
                <p class="text-muted mb-3">{{ trans('plugins/emiron-fx::fx.rates.publish_help', ['threshold' => $threshold]) }}</p>

                @if ($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($marketDrift->isNotEmpty())
                    <div class="alert alert-warning">
                        <strong>{{ trans('plugins/emiron-fx::fx.sources.market_banner') }}</strong>
                        @foreach ($marketDrift as $m)
                            <span class="ms-2">{{ $m['currency']->code }} {{ sprintf('%+.2f%%', $m['drift']) }}</span>
                        @endforeach
                    </div>
                @endif

                @if ($currencies->isEmpty())
                    <div class="alert alert-warning mb-0">
                        {{ trans('plugins/emiron-fx::fx.rates.no_active') }}
                        @if (fx_can('fx.currencies.index'))
                            <a href="{{ route('fx.currencies.index') }}">{{ trans('plugins/emiron-fx::fx.currencies.title') }}</a>
                        @endif
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-vcenter">
                            <thead>
                                <tr>
                                    <th>{{ trans('plugins/emiron-fx::fx.currencies.code') }}</th>
                                    <th class="text-end">{{ trans('plugins/emiron-fx::fx.rates.official') }}</th>
                                    <th class="text-end">{{ trans('plugins/emiron-fx::fx.rates.reference') }}</th>
                                    <th class="text-end">{{ trans('plugins/emiron-fx::fx.rates.current') }}</th>
                                    <th style="width:170px">{{ trans('plugins/emiron-fx::fx.rates.buy') }}</th>
                                    <th style="width:170px">{{ trans('plugins/emiron-fx::fx.rates.sell') }}</th>
                                    <th class="text-end">{{ trans('plugins/emiron-fx::fx.rates.deviation') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($currencies as $currency)
                                    @php
                                        $cur = $current->get($currency->id);
                                        $off = $official->get($currency->id);
                                        $sug = $suggestions[$currency->id] ?? null;
                                        $prev = $previous->get($currency->id);
                                        $mk = $market->get($currency->id);
                                        $mkRec = $mk['recommended'] ?? null;
                                        $mkMid = $mk['consensus']['mid'] ?? null;
                                    @endphp
                                    <tr data-fx-row data-official="{{ $off ? (string) $off->rate : '' }}">
                                        <td>
                                            <strong>{{ $currency->code }}</strong>
                                            @if ($currency->unit > 1)
                                                <small class="text-muted">× {{ $currency->unit }}</small>
                                            @endif
                                            <div class="text-muted small">{{ $currency->name }}</div>
                                        </td>
                                        <td class="text-end">
                                            @if ($off)
                                                {{ fx_rate($off->rate) }}
                                                <div class="small {{ $off->rate_date->isToday() ? 'text-muted' : 'text-warning' }}">{{ $off->rate_date->format('d.m.Y') }}</div>
                                            @else
                                                <span class="text-warning small">{{ trans('plugins/emiron-fx::fx.rates.no_official') }}</span>
                                            @endif
                                        </td>
                                        <td class="text-end small">
                                            @forelse ($referenceLabels as $rSource => $rLabel)
                                                @php $ref = $references->get($rSource)?->get($currency->id); @endphp
                                                @if ($ref)
                                                    @php $div = $off ? (float) app(\Botble\EmironFx\Services\FxRateService::class)->deviationPercent((string) $ref->rate, (string) $off->rate) : null; @endphp
                                                    <div title="{{ $ref->basis }}">
                                                        <span class="text-muted">{{ $rLabel }}</span> {{ fx_rate($ref->rate) }}
                                                        @if ($div !== null)
                                                            <span class="{{ abs($div) > (float) config('plugins.emiron-fx.general.reference_divergence_alert_percent', 1.5) ? 'text-danger fw-bold' : 'text-muted' }}">({{ sprintf('%+.2f', $div) }}%)</span>
                                                        @endif
                                                    </div>
                                                @endif
                                            @empty
                                            @endforelse
                                            @if ($mkMid !== null)
                                                <div class="fw-bold" title="{{ implode(', ', array_keys($mk['consensus']['sources'])) }}">
                                                    {{ trans('plugins/emiron-fx::fx.sources.market_short') }} {{ fx_rate($mkMid) }}
                                                    @if ($mk['drift'] !== null)<span class="text-muted">({{ sprintf('%+.2f%%', $mk['drift']) }})</span>@endif
                                                </div>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            @if ($cur)
                                                {!! fx_trend(app(\Botble\EmironFx\Services\FxRateService::class)->trend((string) $cur->buy_rate, $prev ? (string) $prev->buy_rate : null), $prev ? (string) $prev->buy_rate : null) !!}{{ fx_rate($cur->buy_rate) }}
                                                / {!! fx_trend(app(\Botble\EmironFx\Services\FxRateService::class)->trend((string) $cur->sell_rate, $prev ? (string) $prev->sell_rate : null), $prev ? (string) $prev->sell_rate : null) !!}{{ fx_rate($cur->sell_rate) }}
                                                <div class="small text-muted">{{ BaseHelper::formatDateTime($cur->effective_at) }}</div>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td>
                                            <input type="text" inputmode="decimal" class="form-control text-end" name="rows[{{ $currency->id }}][buy]"
                                                   value="{{ old('rows.' . $currency->id . '.buy') }}" placeholder="{{ $cur ? fx_rate($cur->buy_rate) : '' }}" data-fx-buy autocomplete="off">
                                        </td>
                                        <td>
                                            <input type="text" inputmode="decimal" class="form-control text-end" name="rows[{{ $currency->id }}][sell]"
                                                   value="{{ old('rows.' . $currency->id . '.sell') }}" placeholder="{{ $cur ? fx_rate($cur->sell_rate) : '' }}" data-fx-sell autocomplete="off">
                                        </td>
                                        <td class="text-end small">
                                            <div data-fx-dev>—</div>
                                            @if ($mkRec)
                                                <div>
                                                    <button type="button" class="btn btn-link btn-sm p-0 fw-bold" data-fx-suggest data-buy="{{ $mkRec['buy'] }}" data-sell="{{ $mkRec['sell'] }}"
                                                            title="{{ trans('plugins/emiron-fx::fx.sources.market_help') }}">
                                                        {{ trans('plugins/emiron-fx::fx.sources.market_recommend_btn') }}: {{ $mkRec['buy'] }} / {{ $mkRec['sell'] }}
                                                    </button>
                                                </div>
                                            @endif
                                            @if ($sug)
                                                <div>
                                                    <button type="button" class="btn btn-link btn-sm p-0" data-fx-suggest data-buy="{{ $sug['buy'] }}" data-sell="{{ $sug['sell'] }}"
                                                            title="{{ trans('plugins/emiron-fx::fx.rates.suggestion_help') }}">
                                                        {{ trans('plugins/emiron-fx::fx.rates.suggestion') }}: {{ $sug['buy'] }} / {{ $sug['sell'] }}
                                                    </button>
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-md-8">
                            <label class="form-label" for="fx-note">{{ trans('plugins/emiron-fx::fx.rates.note') }}</label>
                            <textarea id="fx-note" name="note" rows="2" maxlength="500" class="form-control" placeholder="{{ trans('plugins/emiron-fx::fx.rates.note_placeholder') }}">{{ old('note') }}</textarea>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            @if ($canOverride)
                                <label class="form-check">
                                    <input type="checkbox" class="form-check-input" name="override" value="1" @checked(old('override'))>
                                    <span class="form-check-label">{{ trans('plugins/emiron-fx::fx.rates.override_label', ['threshold' => $threshold]) }}</span>
                                </label>
                            @endif
                        </div>
                    </div>
                @endif
            </div>

            @if ($currencies->isNotEmpty())
                <div class="card-footer d-flex justify-content-between align-items-center">
                    <span class="text-muted small">{{ trans('plugins/emiron-fx::fx.rates.immutable_note') }}</span>
                    <button type="submit" class="btn btn-primary">{{ trans('plugins/emiron-fx::fx.rates.publish_button') }}</button>
                </div>
            @endif
        </div>
    </form>

    <script>
        (function () {
            var form = document.querySelector('.fx-publish');
            if (!form) return;
            var threshold = parseFloat(form.getAttribute('data-fx-threshold')) || 3;

            function num(v) {
                v = (v || '').replace(/\s/g, '').replace(',', '.');
                return v === '' || isNaN(v) ? null : parseFloat(v);
            }

            function dev(rate, official) {
                return (rate === null || !official) ? null : ((rate - official) / official) * 100;
            }

            function paint(row) {
                var official = num(row.getAttribute('data-official'));
                var buy = num(row.querySelector('[data-fx-buy]').value);
                var sell = num(row.querySelector('[data-fx-sell]').value);
                var cell = row.querySelector('[data-fx-dev]');
                var parts = [dev(buy, official), dev(sell, official)];
                var warn = parts.some(function (d) { return d !== null && Math.abs(d) > threshold; });
                var bad = buy !== null && sell !== null && buy >= sell;

                cell.textContent = parts.map(function (d) { return d === null ? '—' : d.toFixed(2) + '%'; }).join(' / ');
                cell.className = bad || warn ? 'text-danger fw-bold' : 'text-muted';
            }

            form.querySelectorAll('[data-fx-row]').forEach(function (row) {
                row.addEventListener('input', function () { paint(row); });
                row.querySelectorAll('[data-fx-suggest]').forEach(function (suggest) {
                    suggest.addEventListener('click', function () {
                        row.querySelector('[data-fx-buy]').value = suggest.getAttribute('data-buy');
                        row.querySelector('[data-fx-sell]').value = suggest.getAttribute('data-sell');
                        paint(row);
                    });
                });
                paint(row);
            });
        })();
    </script>
    <style>
        .fx-trend{font-size:10px; margin-right:3px;} .fx-trend--up{color:#16a34a;} .fx-trend--down{color:#dc2626;}
        .fx-sr{position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0,0,0,0);}
    </style>
@endsection
