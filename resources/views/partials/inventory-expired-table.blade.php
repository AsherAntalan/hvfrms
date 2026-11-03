@php
    $rows = $rows ?? $expiredBatches;
    $showBranch = $showBranch ?? false;
    $mainBid = $mainBid ?? 0;
    $showActions = ! auth()->user()->isAdmin();
    $colspan = ($showBranch ? 6 : 6) + ($showActions ? 1 : 0);
@endphp
<div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead>
            <tr>
                @if($showBranch)<th class="ps-3">Branch</th>@endif
                <th class="{{ $showBranch ? '' : 'ps-3' }}">Product</th>
                @unless($showBranch)<th>Category</th>@endunless
                <th>Batch QR</th>
                <th class="text-center">Qty Left</th>
                <th>Expired On</th>
                @unless($showBranch)<th>Received</th>@endunless
                @if($showBranch)<th class="text-center">Days Expired</th>@endif
                @if($showActions)<th class="text-center">Actions</th>@endif
            </tr>
        </thead>
        <tbody>
        @forelse($rows as $b)
            @php $daysAgo = max(0, (int) round((time() - strtotime($b->expiry_date)) / 86400)); @endphp
            <tr class="row-expired table-secondary" data-name="{{ e(strtolower($b->item_name)) }}">
                @if($showBranch)<td class="ps-3 fw-semibold">{{ e($b->branch_name) }}</td>@endif
                <td class="{{ $showBranch ? 'fw-semibold' : 'ps-3 fw-semibold' }}">{{ e($b->item_name) }}</td>
                @unless($showBranch)
                <td>
                    @if($b->category_name)
                        <span class="hv-badge {{ ($b->goods_type ?? '') === 'wet' ? 'hv-badge-info' : 'hv-badge-warning' }}">{{ e($b->category_name) }}</span>
                    @else
                        <span class="text-muted">&mdash;</span>
                    @endif
                </td>
                @endunless
                <td><code class="small">{{ $b->qr_code ?? '—' }}</code></td>
                <td class="text-center fw-bold text-danger">{{ $b->quantity_left }} <span class="text-muted small fw-normal">{{ $b->unit }}</span></td>
                <td>
                    <span class="text-danger fw-semibold">{{ date('M j, Y', strtotime($b->expiry_date)) }}</span>
                    @unless($showBranch)<div class="small text-danger"><i class="ph-fill ph-x-circle"></i> {{ $daysAgo }} day{{ $daysAgo !== 1 ? 's' : '' }} ago</div>@endunless
                </td>
                @unless($showBranch)<td class="small text-muted">{{ $b->received_at ? date('M j, Y', strtotime($b->received_at)) : '—' }}</td>@endunless
                @if($showBranch)<td class="text-center"><span class="text-danger fw-semibold">{{ $daysAgo }}</span> <span class="small text-muted">day{{ $daysAgo !== 1 ? 's' : '' }}</span></td>@endif
                @if($showActions)
                <td class="text-center">
                    <div class="d-flex gap-1 justify-content-center flex-wrap">
                        @php
                            $canDispose = ($canDisposeBatches ?? false);
                            $canVoid = ($canManageProducts ?? false) && (int) $b->branch_id === (int) $mainBid;
                        @endphp
                        @unless($canDispose || $canVoid)<span class="small text-muted">View only</span>@endunless
                        @if($canDispose)
                        <button class="btn btn-sm btn-warning" title="Dispose expired batch"
                                onclick="disposeBatch({{ $b->id }}, '{{ e($b->item_name) }}', {{ $b->quantity_left }})">
                            <i class="ph ph-recycle me-1"></i> Dispose
                        </button>
                        @endif
                        @if($canVoid)
                        <button class="btn btn-sm btn-outline-danger" title="Void expired batch"
                                onclick="voidBatch({{ $b->id }}, '{{ e($b->item_name) }}', {{ $b->quantity_left }})">
                            <i class="ph ph-x-circle"></i>
                        </button>
                        @endif
                    </div>
                </td>
                @endif
            </tr>
        @empty
            <tr><td colspan="{{ $colspan }}" class="text-center text-muted py-4">{{ $showBranch ? 'No expired stock matches these filters. Try clearing them.' : 'No expired stock.' }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
