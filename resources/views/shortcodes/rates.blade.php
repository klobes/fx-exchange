@php
    $fxRows = $board['rows'];
    $fxVersion = @filemtime(plugin_path('emiron-fx/public/css/fx-public.css')) ?: '1';
    $fxData = collect($fxRows)->map(fn ($r) => [
        'code' => $r['code'],
        'unit' => (int) $r['unit'],
        'decimals' => (int) $r['decimals'],
        'buy' => (float) $r['buy'],
        'sell' => (float) $r['sell'],
    ])->values();
@endphp
<link rel="stylesheet" href="{{ asset('vendor/core/plugins/emiron-fx/css/fx-public.css') }}?v={{ $fxVersion }}">

<section class="fx-section" id="kursi">
    <div class="fx-wrap">
        @if ($shortcode->title || $shortcode->subtitle)
            <div class="fx-head">
                @if ($shortcode->title)
                    <h2>{{ $shortcode->title }}</h2>
                @endif
                @if ($shortcode->subtitle)
                    <p>{{ $shortcode->subtitle }}</p>
                @endif
            </div>
        @endif

        <p class="fx-against">{{ trans('plugins/emiron-fx::fx.public.against') }}</p>

        <div @class(['fx-grid', 'fx-grid--single' => ! $showConverter])>
            <div class="fx-board">
                <table>
                    <thead>
                        <tr>
                            <th>{{ trans('plugins/emiron-fx::fx.public.currency') }}</th>
                            <th class="fx-num">{{ trans('plugins/emiron-fx::fx.public.we_buy') }}<small>{{ trans('plugins/emiron-fx::fx.public.we_buy_hint') }}</small></th>
                            <th class="fx-num">{{ trans('plugins/emiron-fx::fx.public.we_sell') }}<small>{{ trans('plugins/emiron-fx::fx.public.we_sell_hint') }}</small></th>
                            @if ($showOfficial)
                                <th class="fx-num fx-official">{{ trans('plugins/emiron-fx::fx.public.official') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($fxRows as $fxRow)
                            <tr>
                                <td>
                                    <span class="fx-code">{{ $fxRow['unit'] }} {{ $fxRow['code'] }}</span>
                                    <span class="fx-name">{{ $fxRow['name'] }}</span>
                                </td>
                                <td class="fx-num">{!! fx_trend($fxRow['buy_trend'], $fxRow['buy_prev']) !!}{{ fx_rate($fxRow['buy']) }} <span class="fx-cur">Lek</span></td>
                                <td class="fx-num">{!! fx_trend($fxRow['sell_trend'], $fxRow['sell_prev']) !!}{{ fx_rate($fxRow['sell']) }} <span class="fx-cur">Lek</span></td>
                                @if ($showOfficial)
                                    <td class="fx-num fx-official">
                                        @if ($fxRow['official'])
                                            {!! fx_trend($fxRow['official_trend'], $fxRow['official_prev']) !!}{{ fx_rate($fxRow['official']) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="fx-meta">
                    {{ trans('plugins/emiron-fx::fx.public.updated', ['time' => $board['updated_at']?->format('d.m.Y H:i')]) }}
                    @if ($showOfficial)
                        · {{ trans('plugins/emiron-fx::fx.public.official_note') }}
                    @endif
                    · {{ trans('plugins/emiron-fx::fx.public.trend_note') }}
                </p>
            </div>

            @if ($showConverter)
                <div class="fx-converter" data-fx-converter data-rates='@json($fxData)'>
                    <h3>{{ trans('plugins/emiron-fx::fx.public.converter') }}</h3>

                    <label class="fx-field">
                        <span>{{ trans('plugins/emiron-fx::fx.public.i_give') }}</span>
                        <span class="fx-inline">
                            <input type="text" inputmode="decimal" value="100" data-fx-amount aria-label="{{ trans('plugins/emiron-fx::fx.public.amount') }}">
                            <select data-fx-from aria-label="{{ trans('plugins/emiron-fx::fx.public.i_give') }}">
                                @foreach ($fxRows as $fxRow)
                                    <option value="{{ $fxRow['code'] }}" @selected($loop->first)>{{ $fxRow['code'] }}</option>
                                @endforeach
                                <option value="ALL">ALL</option>
                            </select>
                        </span>
                    </label>

                    <button type="button" class="fx-swap" data-fx-swap aria-label="{{ trans('plugins/emiron-fx::fx.public.swap') }}">⇅</button>

                    <label class="fx-field">
                        <span>{{ trans('plugins/emiron-fx::fx.public.i_get') }}</span>
                        <span class="fx-inline">
                            <output data-fx-result>—</output>
                            <select data-fx-to aria-label="{{ trans('plugins/emiron-fx::fx.public.i_get') }}">
                                <option value="ALL" selected>ALL</option>
                                @foreach ($fxRows as $fxRow)
                                    <option value="{{ $fxRow['code'] }}">{{ $fxRow['code'] }}</option>
                                @endforeach
                            </select>
                        </span>
                    </label>

                    <p class="fx-used" data-fx-used></p>
                    <p class="fx-disclaimer">{{ trans('plugins/emiron-fx::fx.public.disclaimer') }}</p>
                </div>
            @endif
        </div>
    </div>
</section>

@if ($showConverter)
    <script src="{{ asset('vendor/core/plugins/emiron-fx/js/fx-converter.js') }}?v={{ @filemtime(plugin_path('emiron-fx/public/js/fx-converter.js')) ?: '1' }}" defer></script>
@endif
