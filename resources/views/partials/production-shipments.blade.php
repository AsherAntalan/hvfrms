@if(auth()->user()->isOwner() || auth()->user()->isMainBranchRole())
@php
    $productionShipments = \Illuminate\Support\Facades\DB::table('order_productions as p')
        ->join('orders as o', 'o.id', '=', 'p.order_id')->join('items as i', 'i.id', '=', 'p.item_id')
        ->join('order_items as oi','oi.id','=','p.order_item_id')
        ->join('branches as b', 'b.id', '=', 'o.branch_id')->where('p.produced_qty', '>', 0)
        ->select('o.id as order_id','o.order_number','o.status','o.labels_print_requested_at','b.branch_name','i.item_name','i.unit','p.produced_qty','p.dispatched_qty','p.expiry_date','p.source_type','oi.received_quantity')
        ->orderByDesc('p.id')->paginate(10, ['*'], 'production_page')->withQueryString()->fragment('productionDeliveryRecords');
    $productionRecordsOpen = request()->has('production_page');
@endphp
@push('styles')
<style>
.production-records-card { overflow: hidden; }
.production-records-toggle { color: #fff; background: var(--maroon); font-weight: 700; border-radius: 0; }
.production-records-toggle:hover,
.production-records-toggle:focus-visible { color: #fff; background: var(--maroon-dark); }
.production-records-toggle .badge { background: #fff !important; color: var(--maroon-dark) !important; }
.production-records-toggle .ph-caret-down { color: #fff; transition: transform .2s ease; }
.production-records-toggle[aria-expanded="true"] .ph-caret-down { transform: rotate(180deg); }
.production-records-pagination .pagination { --bs-pagination-color: #333; --bs-pagination-active-bg: var(--maroon); --bs-pagination-active-border-color: var(--maroon); gap: 6px; }
.production-records-pagination .page-link { border-radius: 7px; }
@media (prefers-reduced-motion: reduce) { .production-records-toggle .ph-caret-down { transition: none; } }
</style>
@endpush
<div class="hv-card production-records-card mb-4">
    <button type="button" class="btn production-records-toggle w-100 text-start d-flex align-items-center justify-content-between gap-3 p-3"
            data-bs-toggle="collapse" data-bs-target="#productionDeliveryRecords" aria-controls="productionDeliveryRecords" aria-expanded="{{ $productionRecordsOpen ? 'true' : 'false' }}">
        <span class="fw-bold">Commissary Production &amp; Delivery Records</span>
        <span class="d-flex align-items-center gap-2 flex-shrink-0">
            <span class="badge rounded-pill text-bg-light">{{ $productionShipments->total() }} records</span>
            <i class="ph ph-caret-down" aria-hidden="true"></i>
        </span>
    </button>
    <div class="collapse {{ $productionRecordsOpen ? 'show' : '' }}" id="productionDeliveryRecords">
        <div class="p-3 pt-0">
    <p class="text-muted small">Order-bound stock, shared product QR scan-outs, and destination receipt. Dispatched goods are in transit until the branch receives them.</p>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Order</th><th>Destination</th><th>Product</th><th>Prepared</th><th>Scanned Out</th><th>Remaining</th><th>Received</th><th>Expiry</th><th>Status</th></tr></thead>
        <tbody>@forelse($productionShipments as $shipment)
            <tr><td><a href="{{ route('orders') }}">{{ $shipment->order_number }}</a></td><td>{{ $shipment->branch_name }}</td><td>{{ $shipment->item_name }}</td>
                <td>{{ $shipment->produced_qty }} {{ $shipment->unit }}</td><td>{{ $shipment->dispatched_qty }}</td><td>{{ max(0, $shipment->produced_qty - $shipment->dispatched_qty) }}</td>
                <td>{{ (int)$shipment->received_quantity }} {{ $shipment->unit }}</td>
                <td>{{ $shipment->source_type === 'picked' ? 'Original lot expiry' : ($shipment->expiry_date ?? 'Non-perishable') }}</td>
                <td>@if(in_array($shipment->status, ['delivered','settled'])) Delivered to {{ $shipment->branch_name }}
                    @elseif($shipment->received_quantity >= $shipment->produced_qty) Received at {{ $shipment->branch_name }}
                    @elseif(in_array($shipment->status, ['cancelled','declined'])) {{ ucfirst($shipment->status) }} — stock retained for review
                    @elseif($shipment->dispatched_qty >= $shipment->produced_qty) Dispatched to {{ $shipment->branch_name }}
                    @elseif($shipment->dispatched_qty > 0) Partially dispatched
                    @else Prepared / Ready for Release @endif</td>
            </tr>
        @empty<tr><td colspan="9" class="text-muted text-center py-3">No prepared production orders yet.</td></tr>@endforelse</tbody>
    </table></div>
    @if($productionShipments->hasPages())
    <nav class="production-records-pagination d-flex justify-content-end mt-3" aria-label="Production records pages">
        <ul class="pagination pagination-sm mb-0 flex-wrap justify-content-end">
            @foreach($productionShipments->onEachSide(1)->linkCollection() as $pageLink)
                <li class="page-item {{ $pageLink['active'] ? 'active' : '' }} {{ $pageLink['url'] ? '' : 'disabled' }}">
                    @if($pageLink['url'])
                        <a class="page-link" href="{{ $pageLink['url'] }}" @if($pageLink['active']) aria-current="page" @endif>{{ $loop->first ? 'Previous' : ($loop->last ? 'Next' : $pageLink['label']) }}</a>
                    @else
                        <span class="page-link" aria-disabled="true">{{ $loop->first ? 'Previous' : ($loop->last ? 'Next' : $pageLink['label']) }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </nav>
    @endif
        </div>
    </div>
</div>
@push('scripts')
<script>
// Pager loads in place (no full reload), so scroll position stays fixed.
document.addEventListener('click', function (e) {
    var link = e.target.closest('.production-records-pagination a.page-link');
    if (!link) { return; }
    var url = link.getAttribute('href');
    var container = document.getElementById('productionDeliveryRecords');
    if (!url || !container) { return; }
    e.preventDefault();
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.text(); })
        .then(function (html) {
            var fresh = new DOMParser().parseFromString(html, 'text/html').getElementById('productionDeliveryRecords');
            if (fresh) { container.innerHTML = fresh.innerHTML; }
        })
        .catch(function () { window.location.href = url; });
});
</script>
@endpush
@endif
