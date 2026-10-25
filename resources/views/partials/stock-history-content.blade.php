{{-- Movement history body (batches + transaction log). Shared by the full
     Movement History page and the inventory row-click modal. --}}
{{-- Batch overview --}}
<div class="card shadow-sm mb-4">
    <div class="card-header fw-bold" style="background:var(--gold-soft);color:var(--maroon);">
        <i class="ph ph-package me-1"></i> Active Batches
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th>Batch #</th>
                        <th>Branch</th>
                        <th>QR Code</th>
                        <th>Qty In</th>
                        <th>Qty Left</th>
                        <th>Expiry</th>
                        <th>Received</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($batches as $b)
                    <tr>
                        <td>{{ $b->id }}</td>
                        <td>{{ $b->branch->branch_name ?? '—' }}</td>
                        <td><code class="small">{{ $b->qr_code ?? '—' }}</code></td>
                        <td>{{ $b->quantity_in }}</td>
                        <td>
                            @if($b->quantity_left <= 0)
                                <span class="badge bg-secondary">0</span>
                            @elseif($b->quantity_left <= ($item->reorder_level ?? 0) / 2)
                                <span class="badge bg-danger">{{ $b->quantity_left }}</span>
                            @else
                                {{ $b->quantity_left }}
                            @endif
                        </td>
                        <td>
                            @if($b->expiry_date)
                                @if($b->expiry_date->isPast())
                                    <span class="badge bg-danger">EXPIRED {{ $b->expiry_date->format('Y-m-d') }}</span>
                                @elseif($b->expiry_date->diffInDays(now()) <= 7)
                                    <span class="text-warning fw-bold">{{ $b->expiry_date->format('Y-m-d') }}</span>
                                @else
                                    {{ $b->expiry_date->format('Y-m-d') }}
                                @endif
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </td>
                        <td>{{ $b->received_at ? $b->received_at->format('Y-m-d H:i') : '—' }}</td>
                        <td>
                            <a href="{{ route('stock.batch', $b) }}" class="btn btn-sm btn-outline-primary">
                                <i class="ph ph-eye"></i>
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="text-center text-muted py-3">No batches found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- Transaction log --}}
<div class="card shadow-sm">
    <div class="card-header fw-bold" style="background:var(--gold-soft);color:var(--maroon);">
        <i class="ph ph-list-bullets me-1"></i> Transaction History
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th>Date/Time</th>
                        <th>Type</th>
                        <th>Qty</th>
                        <th>Batch #</th>
                        <th>Branch</th>
                        <th>Reason</th>
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
                        <td>
                            @if($tx->batch_id)
                                <a href="{{ route('stock.batch', $tx->batch_id) }}">#{{ $tx->batch_id }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $tx->branch->branch_name ?? '—' }}</td>
                        <td class="small">{{ $tx->reason }}</td>
                        <td class="small">{{ $tx->performer->full_name ?? '—' }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="text-center text-muted py-3">No transactions found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($transactions->hasPages())
    <div class="card-footer">{{ $transactions->links() }}</div>
    @endif
</div>
