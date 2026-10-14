<!DOCTYPE html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ trans('plugins/emiron-fx::fx.board.title') }}</title>
    @php $landscape = $layout === 'landscape'; @endphp
    <style>
        @page { size: A4 {{ $landscape ? 'landscape' : 'portrait' }}; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #e9ecef; font-family: Arial, Helvetica, sans-serif; color: #111; }
        .tools { position: fixed; top: 0; left: 0; right: 0; background: #212529; color: #fff; padding: 8px 12px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; font-size: 14px; z-index: 9; }
        .tools a, .tools button { background: #fff; color: #111; border: 0; border-radius: 4px; padding: 6px 12px; font-size: 14px; cursor: pointer; text-decoration: none; }
        .tools a.on { background: #ffc107; font-weight: bold; }
        .tools .hint { opacity: .8; font-size: 12px; }
        .sheet { width: {{ $landscape ? '297mm' : '210mm' }}; height: {{ $landscape ? '210mm' : '297mm' }}; margin: 60px auto 20px; background: #fff; overflow: hidden; position: relative; }
        .half { width: 100%; padding: 10mm 12mm; display: flex; flex-direction: column; }
        .tent .half { height: 148.5mm; }
        .tent .half.top { transform: rotate(180deg); border-bottom: 1px dashed #999; }
        .single .half { height: 100%; }
        .subject { text-align: center; font-size: 8mm; font-weight: bold; line-height: 1.1; }
        .info { text-align: center; font-size: 3.8mm; color: #444; margin: 1mm 0 3mm; }
        h1 { margin: 0 0 1mm; font-size: 9mm; text-align: center; letter-spacing: 2px; text-transform: uppercase; border-top: 1.2mm solid #111; padding-top: 2mm; }
        .sub { text-align: center; font-size: 4mm; color: #444; margin-bottom: 4mm; }
        .wrap { flex: 1; display: flex; align-items: center; justify-content: center; }
        table { width: 78%; border-collapse: collapse; }
        th { font-size: 5.5mm; background: #111; color: #fff; padding: 2.5mm 4mm; text-align: center; }
        td { border-bottom: 1.5px solid #888; padding: 3.5mm 4mm; text-align: center; font-weight: bold; font-size: 13mm; line-height: 1; white-space: nowrap; }
        td.cur { text-align: left; font-size: 11mm; width: 30%; }
        td.cur small { display: block; font-size: 3.4mm; font-weight: normal; color: #555; margin-top: 1mm; white-space: normal; }
        .landscape-note { display: none; }
        .foot { text-align: center; font-size: 3.6mm; color: #555; margin-top: 3mm; }
        .empty { text-align: center; padding: 20mm; font-size: 6mm; }
        @media print {
            html, body { background: #fff; }
            .tools { display: none; }
            .sheet { margin: 0; width: 100%; height: 100vh; }
        }
    </style>
</head>
<body>
    <div class="tools">
        <button type="button" onclick="window.print()">{{ trans('plugins/emiron-fx::fx.board.print') }}</button>
        <span>{{ trans('plugins/emiron-fx::fx.board.layout') }}:</span>
        <a href="{{ route('fx.rates.board', ['layout' => 'tent']) }}" @class(['on' => $layout === 'tent'])>{{ trans('plugins/emiron-fx::fx.board.layout_tent') }}</a>
        <a href="{{ route('fx.rates.board', ['layout' => 'portrait']) }}" @class(['on' => $layout === 'portrait'])>{{ trans('plugins/emiron-fx::fx.board.layout_portrait') }}</a>
        <a href="{{ route('fx.rates.board', ['layout' => 'landscape']) }}" @class(['on' => $layout === 'landscape'])>{{ trans('plugins/emiron-fx::fx.board.layout_landscape') }}</a>
        <a href="javascript:history.back()">{{ trans('plugins/emiron-fx::fx.board.back') }}</a>
        @if ($layout === 'tent')
            <span class="hint">{{ trans('plugins/emiron-fx::fx.board.hint_tent') }}</span>
        @endif
    </div>

    @php
        $table = function () use ($rows, $officeName, $officeInfo, $updatedAt, $layout) {
            return view('plugins/emiron-fx::rates._board_half', compact('rows', 'officeName', 'officeInfo', 'updatedAt', 'layout'))->render();
        };
    @endphp

    @if ($layout === 'tent')
        <div class="sheet tent">
            <div class="half top">{!! $table() !!}</div>
            <div class="half">{!! $table() !!}</div>
        </div>
    @else
        <div class="sheet single">
            <div class="half">{!! $table() !!}</div>
        </div>
    @endif
</body>
</html>
