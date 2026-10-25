{{-- Batch detail body (info + stock/expiry + batch movement log). Shared by
     the full Batch Detail page and the inventory history modal. --}}
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-header fw-bold" style="background:var(--gold-soft);color:var(--maroon);">Batch Info</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><th style="width:40%;">Product</th><td>{{ $batch->item->item_name ?? '—' }}</td></tr>
                    <tr><th>Batch ID</th><td>#{{ $batch->id }}</td></tr>
                    <tr><th>QR Code</th><td><code>{{ $batch->qr_code ?? '—' }}</code></td></tr>
                    <tr><th>Branch</th><td>{{ $batch->branch->branch_name ?? '—' }}</td></tr>
                    <tr><th>Received</th><td>{{ $batch->received_at ? $batch->received_at->format('Y-m-d H:i') : '—' }}</td></tr>
                    <tr><th>Created By</th><td>{{ $batch->creator->full_name ?? '—' }}</td></tr>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-header fw-bold" style="background:var(--gold-soft);color:var(--maroon);">Stock & Expiry</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><th style="width:40%;">Original Qty</th><td>{{ $batch->quantity_in }}</td></tr>
                    <tr>
                        <th>Remaining Qty</th>
                        <td>
                            @if($batch->quantity_left <= 0)
                                <span class="badge bg-secondary">0 — Depleted</span>
                            @else
                                <span class="fw-bold">{{ $batch->quantity_left }}</span>
                                <span class="text-muted small">({{ round(($batch->quantity_left / max(1,$batch->quantity_in)) * 100) }}%)</span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <th>Expiry Date</th>
                        <td>
                            @if($batch->expiry_date)
                                @if($batch->expiry_date->isPast())
                                    <span class="badge bg-danger">EXPIRED {{ $batch->expiry_date->format('Y-m-d') }}</span>
                                @elseif($batch->expiry_date->diffInDays(now()) <= 7)
                                    <span class="text-warning fw-bold">{{ $batch->expiry_date->format('Y-m-d') }} ({{ $batch->expiry_date->diffInDays(now()) }} day(s) left)</span>
                                @else
                                    {{ $batch->expiry_date->format('Y-m-d') }} ({{ $batch->expiry_date->diffInDays(now()) }} day(s) left)
                                @endif
                            @else
                                <span class="text-muted">No expiry</span>
                            @endif
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header fw-bold" style="background:var(--gold-soft);color:var(--maroon);">
        <i class="ph ph-list-bullets me-1"></i> Batch Movement History
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th>Date/Time</th>
                        <th>Type</th>
                        <th>Qty</th>
                        <th>Reason</th>
                        <th>Remarks</th>
                        <th>By</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($transactions as $tx)
                    <tr>
                        <td class="small">{{ $tx->created_at->format('Y-m-d H:i') }}</td>
                        <td>
                            @php
                                $colors = ['in'=>'success','out'=>'danger','adjustment'=>'warning','expired'=>'dark','damaged'=>'secondary'];
                            @endphp
                            <span class="badge bg-{{ $colors[$tx->type] ?? 'secondary' }}">{{ strtoupper($tx->type) }}</span>
                        </td>
                        <td class="{{ $tx->quantity >= 0 ? 'text-success' : 'text-danger' }} fw-bold">
                            {{ $tx->quantity >= 0 ? '+' : '' }}{{ $tx->quantity }}
                        </td>
                        <td class="small">{{ $tx->reason }}</td>
                        <td class="small">{{ $tx->remarks ?? '—' }}</td>
                        <td class="small">{{ $tx->performer->full_name ?? '—' }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="text-center text-muted py-3">No transactions for this batch.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
