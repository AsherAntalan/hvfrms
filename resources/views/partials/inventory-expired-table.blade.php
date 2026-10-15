            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Product</th>
                            <th>Category</th>
                            <th>Batch QR</th>
                            <th class="text-center">Qty Left</th>
                            <th>Expired On</th>
                            <th>Received</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($expiredBatches as $b)
                        @php $daysAgo = max(0, (int)round((time() - strtotime($b->expiry_date)) / 86400)); @endphp
                        <tr class="row-expired table-secondary" data-name="{{ e(strtolower($b->item_name)) }}">
                            <td class="ps-3 fw-semibold">{{ e($b->item_name) }}</td>
                            <td>
                                @if($b->category_name)
                                    <span class="hv-badge {{ ($b->goods_type ?? '') === 'wet' ? 'hv-badge-info' : 'hv-badge-warning' }}">{{ e($b->category_name) }}</span>
                                @else
                                    <span class="text-muted">&mdash;</span>
                                @endif
                            </td>
                            <td><code class="small">{{ $b->qr_code ?? '—' }}</code></td>
                            <td class="text-center fw-bold text-danger">{{ $b->quantity_left }} <span class="text-muted small fw-normal">{{ $b->unit }}</span></td>
                            <td>
                                <span class="text-danger fw-semibold">{{ date('M j, Y', strtotime($b->expiry_date)) }}</span>
                                <div class="small text-danger"><i class="bi bi-x-circle-fill"></i> {{ $daysAgo }} day{{ $daysAgo !== 1 ? 's' : '' }} ago</div>
                            </td>
                            <td class="small text-muted">{{ $b->received_at ? date('M j, Y', strtotime($b->received_at)) : '—' }}</td>
                            <td class="text-center">
                                <div class="d-flex gap-1 justify-content-center flex-wrap">
                                    @unless(($canDisposeBatches ?? false) || ($canArchiveBatches ?? false) || $canManageProducts)<span class="small text-muted">View only</span>@endunless
                                    @if($canDisposeBatches ?? false)
                                    <button class="btn btn-sm btn-warning" title="Dispose expired batch"
                                            onclick="disposeBatch({{ $b->id }}, '{{ e($b->item_name) }}', {{ $b->quantity_left }})">
                                        <i class="bi bi-recycle me-1"></i> Dispose
                                    </button>
                                    @endif
                                    @if($canArchiveBatches ?? false)
                                    <button class="btn btn-sm btn-outline-secondary" title="Archive this expired batch"
                                            onclick="archiveBatch({{ $b->id }}, '{{ e($b->item_name) }}')">
                                        <i class="bi bi-archive me-1"></i> Archive
                                    </button>
                                    @endif
                                    @if($canManageProducts)
                                    <button class="btn btn-sm btn-outline-danger" title="Void expired batch"
                                            onclick="voidBatch({{ $b->id }}, '{{ e($b->item_name) }}', {{ $b->quantity_left }})">
                                        <i class="bi bi-x-octagon"></i>
                                    </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">No expired stock.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>