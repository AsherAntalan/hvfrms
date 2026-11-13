{{--
    Reusable client-side table paginator (inventory-style "Showing X–Y of N").
    Include once per page, then call:
        window.hvListPager.attach({ tbody: '#myTbody', noun: 'request', nounPlural: 'requests' });
    Data rows are the tbody's <tr> with more than one cell; add data-pager-skip to
    exclude a row (e.g. an empty-state placeholder). Pass `filter` to paginate only
    a subset (receives a row, returns bool).
--}}
@once
@push('scripts')
<script>
window.hvListPager = (function () {
    function pageList(cur, total) {
        var out = [], w = 2, start = Math.max(1, cur - w), end = Math.min(total, cur + w);
        if (start > 1) { out.push(1); if (start > 2) { out.push('…'); } }
        for (var p = start; p <= end; p++) { out.push(p); }
        if (end < total) { if (end < total - 1) { out.push('…'); } out.push(total); }
        return out;
    }
    function attach(opts) {
        var tbody = typeof opts.tbody === 'string' ? document.querySelector(opts.tbody) : opts.tbody;
        if (!tbody) { return null; }
        var per = opts.perPage || 10;
        var noun = opts.noun || 'match';
        var nounPlural = opts.nounPlural || (noun + 'es');
        var table = tbody.closest('table');
        var host = table.closest('.table-responsive') || table;
        var rowsAll = Array.prototype.slice.call(tbody.rows).filter(function (r) {
            return r.cells.length > 1 && !r.hasAttribute('data-pager-skip');
        });
        if (rowsAll.length <= per && !opts.always) { return null; }

        var bar = document.createElement('div');
        bar.className = 'd-flex flex-wrap justify-content-between align-items-center gap-2 mt-2 px-1 no-print';
        bar.innerHTML = '<div class="text-muted small" role="status" aria-live="polite"></div><nav class="hv-inv-pager" aria-label="Pagination"></nav>';
        host.parentNode.insertBefore(bar, host.nextSibling);
        var info = bar.querySelector('div'), nav = bar.querySelector('nav');
        var page = 1;

        function btn(label, target, o) {
            o = o || {};
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'hv-page-btn' + (o.active ? ' active' : '');
            b.innerHTML = label;
            if (o.disabled) { b.disabled = true; }
            if (o.active) { b.setAttribute('aria-current', 'page'); }
            if (!o.disabled && !o.active) { b.addEventListener('click', function () { page = target; render(); }); }
            return b;
        }
        function render() {
            var rows = opts.filter ? rowsAll.filter(opts.filter) : rowsAll;
            var pages = Math.max(1, Math.ceil(rows.length / per));
            if (page > pages) { page = pages; }
            if (page < 1) { page = 1; }
            var start = (page - 1) * per, end = start + per;
            rowsAll.forEach(function (r) { r.style.display = 'none'; });
            rows.forEach(function (r, i) { if (i >= start && i < end) { r.style.display = ''; } });
            info.textContent = rows.length
                ? 'Showing ' + (start + 1) + '–' + Math.min(end, rows.length) + ' of ' + rows.length + ' ' + (rows.length === 1 ? noun : nounPlural)
                : 'No ' + nounPlural;
            nav.innerHTML = '';
            if (pages > 1) {
                nav.appendChild(btn('<i class="ph ph-caret-left"></i> Prev', page - 1, { disabled: page === 1 }));
                pageList(page, pages).forEach(function (p) {
                    if (p === '…') { var s = document.createElement('span'); s.className = 'hv-page-gap'; s.textContent = '…'; nav.appendChild(s); }
                    else { nav.appendChild(btn(String(p), p, { active: p === page })); }
                });
                nav.appendChild(btn('Next <i class="ph ph-caret-right"></i>', page + 1, { disabled: page === pages }));
            }
        }
        window.addEventListener('beforeprint', function () { rowsAll.forEach(function (r) { r.style.display = ''; }); });
        window.addEventListener('afterprint', render);
        render();
        return { render: render, reset: function () { page = 1; render(); } };
    }
    return { attach: attach };
})();
</script>
@endpush
@endonce
