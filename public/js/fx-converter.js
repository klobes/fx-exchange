(function () {
    'use strict';

    // Konvertuesi publik. Formulat jane te njejtat si FxRateService::convert():
    //   X -> ALL : shuma * blerja_X / njesia_X        (zyra blen X)
    //   ALL -> Y : shuma / (shitja_Y / njesia_Y)       (zyra shet Y)
    //   X -> Y   : permes Lek-ut, me te dyja kurset
    function init(box) {
        var rates = {};
        try {
            JSON.parse(box.getAttribute('data-rates') || '[]').forEach(function (r) { rates[r.code] = r; });
        } catch (e) { return; }

        var amount = box.querySelector('[data-fx-amount]');
        var from = box.querySelector('[data-fx-from]');
        var to = box.querySelector('[data-fx-to]');
        var out = box.querySelector('[data-fx-result]');
        var used = box.querySelector('[data-fx-used]');
        var swap = box.querySelector('[data-fx-swap]');

        function parse(v) {
            v = (v || '').replace(/\s/g, '').replace(',', '.');
            return v === '' || isNaN(v) ? null : parseFloat(v);
        }

        function fmt(n, d) {
            return n.toLocaleString('sq-AL', { minimumFractionDigits: d, maximumFractionDigits: d });
        }

        function calc() {
            var a = parse(amount.value);
            var f = from.value, t = to.value;
            if (a === null || a < 0) { out.textContent = '—'; used.textContent = ''; return; }
            if (f === t) { out.textContent = fmt(a, 2); used.textContent = ''; return; }

            var lek = a, notes = [];
            if (f !== 'ALL') {
                var rf = rates[f];
                lek = a * rf.buy / rf.unit;
                notes.push(f + ' → ALL: ' + fmt(rf.buy, 2) + (rf.unit > 1 ? ' / ' + rf.unit : ''));
            }

            var result = lek, decimals = 0;
            if (t !== 'ALL') {
                var rt = rates[t];
                result = lek / (rt.sell / rt.unit);
                decimals = rt.decimals;
                notes.push('ALL → ' + t + ': ' + fmt(rt.sell, 2) + (rt.unit > 1 ? ' / ' + rt.unit : ''));
            }

            out.textContent = fmt(result, decimals);
            used.textContent = notes.join(' · ');
        }

        amount.addEventListener('input', calc);
        from.addEventListener('change', calc);
        to.addEventListener('change', calc);
        if (swap) {
            swap.addEventListener('click', function () {
                var f = from.value;
                from.value = to.value;
                to.value = f;
                calc();
            });
        }
        calc();
    }

    function boot() { document.querySelectorAll('[data-fx-converter]').forEach(init); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
