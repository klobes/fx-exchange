{{-- Numërimi i arkës: $codes, $balances, $showExpected --}}
<div class="table-responsive">
    <table class="table table-vcenter">
        <thead>
            <tr>
                <th>{{ trans('plugins/emiron-fx::fx.counter.currency') }}</th>
                @if ($showExpected)
                    <th class="text-end">{{ trans('plugins/emiron-fx::fx.counter.expected') }}</th>
                @endif
                <th style="width:220px">{{ trans('plugins/emiron-fx::fx.counter.counted') }}</th>
                @if ($showExpected)
                    <th class="text-end">{{ trans('plugins/emiron-fx::fx.counter.difference') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($codes as $code)
                @php $exp = $balances[$code] ?? '0.00'; @endphp
                <tr data-fx-count-row data-expected="{{ $exp }}">
                    <td><strong>{{ $code }}</strong></td>
                    @if ($showExpected)
                        <td class="text-end font-monospace">{{ number_format((float) $exp, 2, '.', ' ') }}</td>
                    @endif
                    <td>
                        <input type="text" inputmode="decimal" class="form-control text-end font-monospace" name="counts[{{ $code }}]"
                               value="{{ old('counts.' . $code) }}" placeholder="0.00" autocomplete="off" data-fx-count>
                    </td>
                    @if ($showExpected)
                        <td class="text-end font-monospace" data-fx-diff>—</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<div class="mb-3">
    <label class="form-label" for="fx-count-note">{{ trans('plugins/emiron-fx::fx.counter.variance_note') }}</label>
    <textarea id="fx-count-note" name="note" rows="2" maxlength="500" class="form-control">{{ old('note') }}</textarea>
</div>

<script>
    (function () {
        document.querySelectorAll('[data-fx-count-row]').forEach(function (row) {
            var input = row.querySelector('[data-fx-count]');
            var cell = row.querySelector('[data-fx-diff]');
            if (!cell) return;
            var expected = parseFloat(row.getAttribute('data-expected')) || 0;
            function paint() {
                var v = (input.value || '').replace(/\s/g, '').replace(',', '.');
                if (v === '' || isNaN(v)) { cell.textContent = '—'; cell.className = 'text-end font-monospace'; return; }
                var d = parseFloat(v) - expected;
                cell.textContent = (d > 0 ? '+' : '') + d.toFixed(2);
                cell.className = 'text-end font-monospace ' + (Math.abs(d) < 0.005 ? 'text-success' : 'text-danger fw-bold');
            }
            input.addEventListener('input', paint);
            paint();
        });
    })();
</script>
