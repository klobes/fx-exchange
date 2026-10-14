<div class="subject">{{ $officeName }}</div>
@if (! empty($officeInfo))
    <div class="info">{{ $officeInfo }}</div>
@endif
<h1>{{ trans('plugins/emiron-fx::fx.board.title') }}</h1>
@if ($updatedAt)
    <div class="sub">{{ trans('plugins/emiron-fx::fx.board.updated', ['date' => $updatedAt->format('d.m.Y'), 'time' => $updatedAt->format('H:i')]) }}</div>
@endif

@if (empty($rows))
    <div class="empty">{{ trans('plugins/emiron-fx::fx.board.empty') }}</div>
@else
    @php
        $cap = ['tent' => 4, 'landscape' => 5, 'portrait' => 9][$layout ?? 'tent'] ?? 4;
        $zoom = count($rows) > $cap ? max(0.4, round($cap / count($rows), 2)) : 1;
    @endphp
    <div class="wrap">
        <table style="zoom: {{ $zoom }}">
            <thead>
                <tr>
                    <th style="text-align:left">{{ trans('plugins/emiron-fx::fx.board.currency') }}</th>
                    <th>{{ trans('plugins/emiron-fx::fx.board.buy') }}</th>
                    <th>{{ trans('plugins/emiron-fx::fx.board.sell') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td class="cur">
                            {{ $row['code'] }}
                            @if ((int) $row['unit'] > 1)
                                <small>{{ trans('plugins/emiron-fx::fx.board.unit', ['unit' => $row['unit']]) }}</small>
                            @endif
                        </td>
                        <td>{{ fx_rate($row['buy']) }}</td>
                        <td>{{ fx_rate($row['sell']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
