            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Product</th>
                            <th>Type</th>
                            <th class="text-center">Qty</th>
                            <th>Reason</th>
                            <th>Remarks</th>
                            <th>Batch QR</th>
                            <th>Batch Expiry</th>
                            <th>Performed By</th>
                            <th>Date</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($disposalRecords as $d)
                        <tr>
                            <td class="ps-3 fw-semibold">{{ e($d->item_name) }}</td>
                            <td>
                                @php
                                    $typeBadge = match($d->type) {
                                        'expired' => 'hv-badge-danger',
                                        'damaged' => 'hv-badge-warning',
                                        default => 'hv-badge-secondary',
                                    };
                                @endphp
                                <span class="hv-badge {{ $typeBadge }}">{{ ucfirst($d->type) }}</span>
                            </td>
                            <td class="text-center fw-bold text-danger">{{ abs($d->quantity) }} <span class="text-muted small fw-normal">{{ $d->unit }}</span></td>
                            <td class="small">{{ e($d->reason) }}</td>
                            <td class="small text-muted">{{ $d->remarks ? e($d->remarks) : '—' }}</td>
                            <td><code class="small">{{ $d->batch_qr ?? '—' }}</code></td>
                            <td class="small">
                                @if($d->batch_expiry)
                                    {{ date('M j, Y', strtotime($d->batch_expiry)) }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="small">{{ $d->performed_by_name ?? '—' }}</td>
                            <td class="small text-muted">{{ \Carbon\Carbon::parse($d->created_at)->format('M j, g:i A') }}</td>
                            <td class="text-center">
                                @if(($canArchiveBatches ?? false) && !empty($d->batch_id) && $d->batch_expiry && $d->batch_expiry < date('Y-m-d'))
                                    <button class="btn btn-sm btn-outline-secondary" title="Archive this expired batch record"
                                            onclick="archiveBatch({{ (int)$d->batch_id }}, '{{ e($d->item_name) }}')">
                                        <i class="ph ph-archive-box me-1"></i> Archive
                                    </button>
                                @else
                                    <span class="text-muted">&mdash;</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-center text-muted py-4">No disposal records yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>