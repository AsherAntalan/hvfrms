<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Labels — {{ $order->order_number }}</title>
<style>
  :root{ --maroon:#7A1717; --gold:#D4A94A; --ink:#2B2B2B; --line:#E7E0D4; }
  *{ box-sizing:border-box; }
  body{ margin:0; font-family:'Segoe UI',system-ui,-apple-system,sans-serif; color:var(--ink); background:#F3EEE6; }
  .bar{ display:flex; gap:.5rem; align-items:center; justify-content:space-between;
        padding:12px 18px; background:#fff; border-bottom:1px solid var(--line); position:sticky; top:0; }
  .bar h1{ font-size:15px; margin:0; color:var(--maroon); }
  .bar button{ border:1px solid var(--maroon); background:var(--maroon); color:#fff; border-radius:8px;
               padding:7px 16px; font-size:13px; cursor:pointer; }
  .sheet{ padding:18px; display:grid; grid-template-columns:repeat(2,1fr); gap:14px; }
  .label{ background:#fff; border:1px solid var(--line); border-radius:12px; padding:14px 16px;
          display:flex; gap:14px; break-inside:avoid; }
  .label .qr{ flex-shrink:0; width:120px; }
  .label .qr img{ width:120px; height:120px; image-rendering:pixelated; }
  .label .qr .code{ font-size:10px; text-align:center; margin-top:4px; letter-spacing:.02em; word-break:break-all; }
  .brand{ font-size:11px; font-weight:800; letter-spacing:.12em; text-transform:uppercase; color:var(--maroon); }
  .prod{ font-size:17px; font-weight:800; letter-spacing:-.01em; margin:2px 0; }
  .row{ font-size:12px; margin:1px 0; }
  .row b{ color:var(--maroon); }
  .qty{ font-size:20px; font-weight:800; }
  .exp{ font-weight:700; }
  .exp.none{ color:#8A8A8A; font-weight:600; }
  @media print{
    body{ background:#fff; }
    .bar{ display:none; }
    .sheet{ padding:0; gap:0; }
    .label{ border:1px solid #bbb; border-radius:0; margin:0; }
    @page{ margin:10mm; }
  }
</style>
</head>
<body>
@php
    $legacyCanPrepare = $unitLabels->isEmpty() && in_array($order->status, ['approved','preparing'])
        && $prods->every(fn ($production) => $production->dispatched_qty == 0 && $production->produced_qty > 0);
@endphp
<div class="bar">
  <h1>Production Labels — {{ $order->order_number }} · {{ $branchName }}</h1>
  <div><span id="printMessage" role="status"></span> <button type="button" id="printLabels" onclick="printProductionLabels()">{{ $legacyCanPrepare ? 'Prepare '.$prods->sum('produced_qty').' Individual Labels' : 'Print '.($unitLabels->isNotEmpty() ? $unitLabels->count() : $prods->count()).' Labels' }}</button></div>
</div>
<p style="padding:0 18px;font-size:13px;">Copies for the same product/order share one QR. Scan once to release or receive the whole selected quantity; do not scan each copy separately.</p>
<div class="sheet">
  @php $labels = $unitLabels->isNotEmpty() ? $unitLabels : $prods; @endphp
  @foreach($labels as $p)
    @php $exp = $p->expiry_date ? \Illuminate\Support\Carbon::parse($p->expiry_date) : null; @endphp
    <div class="label" data-unit-label="{{ $p->group_qr_code ?? $p->qr_code }}">
      <div class="qr"><div class="qrimg" data-code="{{ $p->group_qr_code ?? $p->qr_code }}"></div><div class="code">{{ $p->group_qr_code ?? $p->qr_code }}</div></div>
      <div class="body">
        <div class="brand">Hidden Vietnam · Tanza Main Branch</div>
        <div class="prod">{{ $p->item_name }}</div>
        <div class="row"><b>Order</b> {{ $order->order_number }} &nbsp; <b>To</b> {{ $branchName }}</div>
        <div class="row"><span class="qty">{{ $unitLabels->isNotEmpty() ? 1 : (int)$p->produced_qty }}</span> {{ $p->unit }}</div>
        <div class="row"><b>{{ ($p->source_type ?? 'produced') === 'picked' ? 'Picked' : 'Produced' }}</b> {{ $p->production_at ? \Illuminate\Support\Carbon::parse($p->production_at)->format('M j, Y g:i A') : '—' }}</div>
        @if($exp)
          <div class="row exp"><b>Expiry</b> {{ $exp->format('M j, Y') }}</div>
        @else
          <div class="row exp none"><b>Expiry</b> Non-perishable</div>
        @endif
        <div class="row">@if($unitLabels->isNotEmpty())<b>Copy</b> {{ $p->unit_number }} of {{ $p->produced_qty }} · shared QR for {{ $p->produced_qty }} {{ $p->unit }}@else<b>Original Lot</b> #{{ $p->id }}@endif</div>
      </div>
    </div>
  @endforeach
</div>
<script src="{{ asset('assets/js/qrcode.min.js') }}"></script>
<script>
  async function printProductionLabels() {
    const button = document.getElementById('printLabels'); button.disabled = true;
    try {
      @if($legacyCanPrepare)
      const expiry = @json($prods->mapWithKeys(fn ($production) => [$production->order_item_id => $production->expiry_date]));
      const prepareBody = new FormData(); prepareBody.append('_token', '{{ csrf_token() }}'); prepareBody.append('action', 'prepare_order'); prepareBody.append('id', '{{ $order->id }}'); prepareBody.append('expiry', JSON.stringify(expiry));
      const prepareResponse = await fetch('{{ route("orders.api") }}', {method:'POST',body:prepareBody,headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
      const prepared = await prepareResponse.json();
      if (!prepared.ok) throw new Error(prepared.error || prepared.message || 'Could not prepare individual labels.');
      location.reload(); return;
      @endif
      @if($unitLabels->isNotEmpty() && in_array($order->status, ['approved','preparing','delivering','delivered','settled']))
      const body = new FormData(); body.append('_token', '{{ csrf_token() }}'); body.append('action', 'print_order_labels'); body.append('id', '{{ $order->id }}');
      const response = await fetch('{{ route("orders.api") }}', {method:'POST',body,headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
      const result = await response.json();
      if (!result.ok) throw new Error(result.error || result.message || 'Could not record the print request.');
      document.getElementById('printMessage').textContent = 'Print requested — use Release / Scan Out to dispatch.';
      @endif
      window.print();
    } catch(error) {document.getElementById('printMessage').textContent = error.message;}
    finally {button.disabled = false;}
  }
  document.querySelectorAll('.qrimg').forEach(function(el){
    try{ var q=qrcode(0,'M'); q.addData(el.dataset.code); q.make(); el.innerHTML=q.createImgTag(3,0); }catch(e){}
  });
</script>
</body>
</html>
