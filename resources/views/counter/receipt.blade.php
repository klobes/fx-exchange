<!DOCTYPE html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <title>{{ $tx->number }}</title>
    <style>
        @page { size: 80mm auto; margin: 3mm; }
        * { box-sizing: border-box; }
        body { width: 74mm; margin: 0 auto; font-family: "DejaVu Sans Mono", Consolas, monospace; font-size: 11.5px; color: #000; }
        .c { text-align: center; }
        .b { font-weight: bold; }
        .big { font-size: 15px; }
        hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 1px 0; vertical-align: top; }
        td.r { text-align: right; }
        .copy { border: 1px solid #000; padding: 2px; margin: 4px 0; }
        .tools { margin: 10px 0; text-align: center; }
        @media print { .tools { display: none; } }
    </style>
</head>
<body>
    @php
        $office = $tx->office;
        $dirLabel = trans('plugins/emiron-fx::fx.receipt.' . $tx->direction);
        // 0.8.0: kryqëzim valutë↔valutë — një kupon, numri i këmbisës 1, pa shfaqur dy veprime
        $first = $cross['first'] ?? $tx;
        $second = $cross['second'] ?? null;
    @endphp

    <div class="c b big">{{ $office->legal_name ?: $office->name }}</div>
    @if ($office->legal_name && $office->legal_name !== $office->name)
        <div class="c">{{ $office->name }}</div>
    @endif
    @if ($office->address)<div class="c">{{ $office->address }}</div>@endif
    @if ($office->nipt)<div class="c">NIPT: {{ $office->nipt }}</div>@endif
    @if ($office->license_no)<div class="c">{{ trans('plugins/emiron-fx::fx.receipt.license') }}: {{ $office->license_no }}</div>@endif
    @if ($office->phone)<div class="c">Tel: {{ $office->phone }}</div>@endif

    <hr>
    <div class="c b">{{ trans('plugins/emiron-fx::fx.receipt.title') }}</div>
    @if ($tx->isReversal())
        <div class="c b copy">{{ trans('plugins/emiron-fx::fx.receipt.reversal', ['number' => $tx->voids?->number]) }}</div>
    @elseif ($tx->voidedBy)
        <div class="c b copy">{{ trans('plugins/emiron-fx::fx.receipt.voided', ['number' => $tx->voidedBy->number]) }}</div>
    @endif
    @if ($copy)
        <div class="c b copy">{{ trans('plugins/emiron-fx::fx.receipt.copy') }}</div>
    @endif

    <table>
        <tr><td>{{ trans('plugins/emiron-fx::fx.receipt.number') }}</td><td class="r b">{{ $first->number }}</td></tr>
        <tr><td>{{ trans('plugins/emiron-fx::fx.receipt.date') }}</td><td class="r">{{ $first->occurred_at->format('d.m.Y H:i:s') }}</td></tr>
        <tr><td>{{ trans('plugins/emiron-fx::fx.receipt.operation') }}</td><td class="r b">{{ $second ? trans('plugins/emiron-fx::fx.receipt.cross') : $dirLabel }}</td></tr>
    </table>
    <hr>
    @if ($second)
        @php
            $given = (float) $first->foreign_amount;       // monedha që jep klienti (zyra blen)
            $got = (float) $second->foreign_amount;        // monedha që merr klienti (zyra shet)
            $effective = $given > 0 ? $got / $given : 0;
        @endphp
        <table>
            <tr><td class="b">{{ trans('plugins/emiron-fx::fx.receipt.cross_gives') }}</td>
                <td class="r b big">{{ number_format($given, 2, '.', ' ') }} {{ $first->currency_code }}</td></tr>
            <tr><td class="b">{{ trans('plugins/emiron-fx::fx.receipt.cross_gets') }}</td>
                <td class="r b big">{{ number_format($got, 2, '.', ' ') }} {{ $second->currency_code }}</td></tr>
            <tr><td>{{ trans('plugins/emiron-fx::fx.receipt.cross_rate') }}</td>
                <td class="r">1 {{ $first->currency_code }} = {{ rtrim(rtrim(number_format($effective, 4, '.', ''), '0'), '.') }} {{ $second->currency_code }}</td></tr>
            @if ($second->change_amount !== null && (float) $second->change_amount > 0)
                <tr><td>{{ trans('plugins/emiron-fx::fx.receipt.cross_change') }}</td>
                    <td class="r">{{ number_format((float) $second->change_amount, $second->change_currency === 'ALL' ? 0 : 2, '.', ' ') }} {{ $second->change_currency === 'ALL' ? 'Lek' : $second->change_currency }}</td></tr>
            @endif
            <tr><td>{{ trans('plugins/emiron-fx::fx.receipt.commission') }}</td><td class="r">0</td></tr>
        </table>
    @else
    <table>
        <tr><td>{{ trans('plugins/emiron-fx::fx.receipt.currency') }}</td><td class="r b">{{ $tx->currency_code }}</td></tr>
        <tr><td>{{ trans('plugins/emiron-fx::fx.receipt.amount') }}</td><td class="r b">{{ number_format((float) $tx->foreign_amount, 2, '.', ' ') }}</td></tr>
        <tr><td>{{ trans('plugins/emiron-fx::fx.receipt.rate') }}</td><td class="r">{{ fx_rate($tx->rate) }}{{ $tx->unit > 1 ? ' / ' . $tx->unit : '' }}</td></tr>
        <tr><td>{{ trans('plugins/emiron-fx::fx.receipt.commission') }}</td><td class="r">0</td></tr>
        <tr><td class="b big">{{ $tx->direction === 'buy' ? trans('plugins/emiron-fx::fx.receipt.paid_lek') : trans('plugins/emiron-fx::fx.receipt.received_lek') }}</td>
            <td class="r b big">{{ number_format((float) $tx->lek_amount, 0, '.', ' ') }} Lek</td></tr>
    </table>
    @endif
    @if (! $second && $tx->official_rate)
        <div class="c" style="margin-top:4px">{{ trans('plugins/emiron-fx::fx.receipt.official', ['rate' => fx_rate($tx->official_rate), 'date' => $tx->official_rate_date?->format('d.m.Y')]) }}</div>
    @endif
    <hr>
    @if ($tx->customer)
        <div>{{ trans('plugins/emiron-fx::fx.receipt.customer') }}: {{ $tx->customer->displayName() }}</div>
        <div>{{ trans('plugins/emiron-fx::fx.doc_types.' . $tx->customer->doc_type) }}: {{ $tx->customer->maskedDoc() }}</div>
        <hr>
    @endif
    <table>
        <tr><td>{{ trans('plugins/emiron-fx::fx.receipt.cashier') }}</td><td class="r">{{ $tx->cashier?->name }}</td></tr>
        <tr><td>{{ trans('plugins/emiron-fx::fx.receipt.till') }}</td><td class="r">{{ $tx->till?->name }}</td></tr>
    </table>
    @if ($tx->isReversal() && $tx->void_reason)
        <div style="margin-top:4px">{{ trans('plugins/emiron-fx::fx.receipt.reason') }}: {{ $tx->void_reason }}</div>
    @endif
    <hr>
    <div class="c">{{ trans('plugins/emiron-fx::fx.receipt.footer') }}</div>
    <div class="c" style="font-size:9px; margin-top:4px">{{ substr($tx->hash, 0, 16) }}</div>

    <div class="tools">
        <button onclick="window.print()">{{ trans('plugins/emiron-fx::fx.receipt.print') }}</button>
        <a href="{{ route('fx.counter.index') }}">{{ trans('plugins/emiron-fx::fx.counter.back') }}</a>
    </div>

    @if ($autoPrint)
        <script>window.addEventListener('load', function () { window.print(); });</script>
    @endif
</body>
</html>
