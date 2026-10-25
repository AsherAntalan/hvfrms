@if(auth()->user()->isOwner() || auth()->user()->isMainBranchRole())
@php
    $productionShipments = \Illuminate\Support\Facades\DB::table('order_productions as p')
        ->join('orders as o', 'o.id', '=', 'p.order_id')->join('items as i', 'i.id', '=', 'p.item_id')
        ->join('order_items as oi','oi.id','=','p.order_item_id')
        ->join('branches as b', 'b.id', '=', 'o.branch_id')->where('p.produced_qty', '>', 0)
        ->select('o.id as order_id','o.order_number','o.status','o.labels_print_requested_at','b.branch_name','i.item_name','i.unit','p.produced_qty','p.dispatched_qty','p.expiry_date','p.source_type','oi.received_quantity')
        ->orderByDesc('p.id')->limit(100)->get();
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
@media (prefers-reduced-motion: reduce) { .production-records-toggle .ph-caret-down { transition: none; } }
</style>
@endpush
<div class="hv-card production-records-card mb-4">
    <button type="button" class="btn production-records-toggle w-100 text-start d-flex align-items-center justify-content-between gap-3 p-3"
            data-bs-toggle="collapse" data-bs-target="#productionDeliveryRecords" aria-controls="productionDeliveryRecords" aria-expanded="false">
        <span class="fw-bold">Commissary Production &amp; Delivery Records</span>
        <span class="d-flex align-items-center gap-2 flex-shrink-0">
            <span class="badge rounded-pill text-bg-light">{{ $productionShipments->count() }} records</span>
            <i class="ph ph-caret-down" aria-hidden="true"></i>
        </span>
    </button>
    <div class="collapse" id="productionDeliveryRecords">
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
        </div>
    </div>
</div>
@endif
