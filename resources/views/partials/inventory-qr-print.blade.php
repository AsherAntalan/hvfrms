@push('scripts')
<script>
function printSingleQR(qr, name, unit) {
    printQRLabels([{ qr: qr, name: name, unit: unit }]);
}

// Load the QR generator once into THIS page, then print via a hidden iframe —
// no new tab/pop-up; the print dialog opens over the current page.
function _ensureQrcode(cb) {
    if (window.qrcode) { cb(); return; }
    var s = document.createElement('script');
    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js';
    s.onload = cb;
    s.onerror = function () { alert('Could not load the QR generator. Check your connection.'); };
    document.head.appendChild(s);
}

function printQRLabels(labels) {
    _ensureQrcode(function () {
        function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
        var cells = labels.map(function (l) {
            var q = window.qrcode(0, 'M'); q.addData(l.qr); q.make();
            return '<div class="lbl">' + q.createSvgTag(3,0)
                 + '<div class="n">' + esc(l.name) + '</div>'
                 + '<div class="c">' + esc(l.qr) + '</div>'
                 + '<div class="u">' + esc(l.unit) + '</div></div>';
        }).join('');

        var css = '*{margin:0;padding:0;box-sizing:border-box}body{font-family:Arial,sans-serif;padding:8mm;color:#222}'
            + '.grid{display:flex;flex-wrap:wrap;gap:5mm;justify-content:center}'
            + '.lbl{width:58mm;border:1px dashed #bbb;border-radius:2mm;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:4mm 3mm;page-break-inside:avoid}'
            + '.lbl svg{width:26mm;height:26mm}.lbl .n{font-weight:700;font-size:9pt;margin-top:2mm;text-align:center}'
            + '.lbl .c{font-size:7.5pt;color:#555;margin-top:1mm;font-family:monospace}.lbl .u{font-size:7pt;color:#999;margin-top:.5mm}'
            + '@media print{body{padding:5mm}}';

        var old = document.getElementById('hvQrPrintFrame');
        if (old) { old.remove(); }
        var f = document.createElement('iframe');
        f.id = 'hvQrPrintFrame';
        f.style.cssText = 'position:fixed;width:0;height:0;border:0;right:0;bottom:0;';
        document.body.appendChild(f);

        var d = f.contentWindow.document;
        d.open();
        d.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>QR Labels</title><style>' + css + '</style></head><body><div class="grid">' + cells + '</div></body></html>');
        d.close();

        var win = f.contentWindow;
        win.onafterprint = function () { setTimeout(function () { f.remove(); }, 100); };
        setTimeout(function () { win.focus(); win.print(); }, 150);
    });
}
</script>
@endpush
