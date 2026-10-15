@push('scripts')
<script>
function printSingleQR(qr, name, unit) {
    printQRLabels([{ qr: qr, name: name, unit: unit }]);
}

function printQRLabels(labels) {
    var w = window.open('', 'qr_print', 'width=820,height=600');
    if (!w) { alert('Please allow pop-ups.'); return; }
    function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
    var d = w.document;
    d.open();
    d.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>QR Labels</title>');
    d.write('<style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:Arial,sans-serif;padding:10mm;color:#222}.bar{text-align:center;margin-bottom:8mm}.bar button{padding:8px 28px;font-size:14px;cursor:pointer;background:#7A1717;color:#fff;border:none;border-radius:6px;font-weight:600}.grid{display:flex;flex-wrap:wrap;gap:5mm;justify-content:center}.lbl{width:58mm;border:1px dashed #bbb;border-radius:2mm;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:4mm 3mm;page-break-inside:avoid}.lbl svg{width:26mm;height:26mm}.lbl .n{font-weight:700;font-size:9pt;margin-top:2mm;text-align:center}.lbl .c{font-size:7.5pt;color:#555;margin-top:1mm;font-family:monospace}.lbl .u{font-size:7pt;color:#999;margin-top:.5mm}@media print{.bar{display:none}body{padding:5mm}}</style></head><body>');
    d.write('<div class="bar"><button onclick="window.print()"><b>Print Labels</b></button> <span style="color:#888;margin-left:10px;">' + labels.length + ' label(s)</span></div>');
    d.write('<div class="grid" id="g"></div></body></html>');
    d.close();
    var scr = d.createElement('script');
    scr.src = 'https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js';
    scr.onload = function () {
        var grid = d.getElementById('g');
        labels.forEach(function (l) {
            var q = w.qrcode(0, 'M'); q.addData(l.qr); q.make();
            var el = d.createElement('div'); el.className = 'lbl';
            el.innerHTML = q.createSvgTag(3,0) + '<div class="n">' + esc(l.name) + '</div><div class="c">' + esc(l.qr) + '</div><div class="u">' + esc(l.unit) + '</div>';
            grid.appendChild(el);
        });
    };
    d.head.appendChild(scr);
}
</script>
@endpush
