            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="text-center" style="width:36px;">
                                <input type="checkbox" class="form-check-input" onchange="toggleAll(this)" title="Select all">
                            </th>
                            <th class="ps-3">Product</th>
                            <th>Category</th>
                            <th>Batch QR</th>
                            <th class="text-center">Qty Left</th>
                            <th class="text-center">Qty In</th>
                            <th>Expiry Date</th>
                            <th>Received</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($activeBatches as $b)
                        @php
                            $warnDate = date('Y-m-d', strtotime('+7 days'));
                            $expSoon = $b->expiry_date && $b->expiry_date <= $warnDate;
                        @endphp
                        <tr class="row-active" data-name="{{ e(strtolower($b->item_name)) }}">
                            <td class="text-center">
                                <input type="checkbox" class="form-check-input qr-cb" onchange="updatePrintBtn()"
                                       data-qr="{{ e($b->qr_code) }}"
                                       data-name="{{ e($b->item_name) }}"
                                       data-unit="{{ e($b->unit) }}">
                            </td>
                            <td class="ps-3 fw-semibold">{{ e($b->item_name) }}</td>
                            <td>
                                @if($b->category_name)
                                    <span class="hv-badge {{ ($b->goods_type ?? '') === 'wet' ? 'hv-badge-info' : 'hv-badge-warning' }}">{{ e($b->category_name) }}</span>
                                @else
                                    <span class="text-muted">&mdash;</span>
                                @endif
                            </td>
                            <td><code class="small" style="color:var(--maroon);">{{ $b->qr_code ?? '—' }}</code></td>
                            <td class="text-center fw-bold">{{ $b->quantity_left }} <span class="text-muted small fw-normal">{{ $b->unit }}</span></td>
                            <td class="text-center text-muted">{{ $b->quantity_in }}</td>
                            <td>
                                @if($b->expiry_date)
                                    <span class="{{ $expSoon ? 'text-warning fw-semibold' : 'text-success' }}">
                                        {{ date('M j, Y', strtotime($b->expiry_date)) }}
                                    </span>
                                    @if($expSoon)
                                        <div class="small text-warning"><i class="bi bi-clock-fill"></i> Soon</div>
                                    @endif
                                @else
                                    <span class="text-muted">&mdash;</span>
                                @endif
                            </td>
                            <td class="small text-muted">
                                {{ $b->received_at ? date('M j, Y', strtotime($b->received_at)) : '—' }}
                                @if($b->full_name)
                                    <div>by {{ $b->full_name }}</div>
                                @endif
                            </td>
                            <td class="text-center"><span class="hv-badge hv-badge-success">Active</span></td>
                            <td class="text-center">
                                <div class="d-flex gap-1 justify-content-center">
                                    @if($canEditBatches)
                                    <button class="btn btn-sm btn-outline-secondary" title="Adjust Quantity"
                                            onclick="openAdjustModal({{ $b->id }}, '{{ e($b->item_name) }}', {{ $b->quantity_left }})">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    @endif
                                    <button class="btn btn-sm btn-outline-secondary" title="Print QR"
                                            onclick="printSingleQR('{{ e($b->qr_code) }}', '{{ e($b->item_name) }}', '{{ e($b->unit) }}')">
                                        <i class="bi bi-printer"></i>
                                    </button>
                                    @if($canManageProducts)
                                    <button class="btn btn-sm btn-outline-danger" title="Void Batch"
                                            onclick="voidBatch({{ $b->id }}, '{{ e($b->item_name) }}', {{ $b->quantity_left }})">
                                        <i class="bi bi-x-octagon"></i>
                                    </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-center text-muted py-4">No active stock at this branch. Received deliveries appear here after confirmation.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>