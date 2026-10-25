            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Product</th>
                            <th>Category</th>
                            <th class="text-center">Qty Left</th>
                            <th class="text-center">Qty In</th>
                            <th>Expiry Date</th>
                            <th>Received</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($activeBatches as $b)
                        @php
                            $warnDate = date('Y-m-d', strtotime('+7 days'));
                            $expSoon = $b->expiry_date && $b->expiry_date <= $warnDate;
                        @endphp
                        <tr class="row-active" data-name="{{ e(strtolower($b->item_name)) }}">
                            <td class="ps-3 fw-semibold">{{ e($b->item_name) }}</td>
                            <td>
                                @if($b->category_name)
                                    <span class="hv-badge {{ ($b->goods_type ?? '') === 'wet' ? 'hv-badge-info' : 'hv-badge-warning' }}">{{ e($b->category_name) }}</span>
                                @else
                                    <span class="text-muted">&mdash;</span>
                                @endif
                            </td>
                            <td class="text-center fw-bold">{{ $b->quantity_left }} <span class="text-muted small fw-normal">{{ $b->unit }}</span></td>
                            <td class="text-center text-muted">{{ $b->quantity_in }}</td>
                            <td>
                                @if($b->expiry_date)
                                    <span class="{{ $expSoon ? 'text-warning fw-semibold' : 'text-success' }}">
                                        {{ date('M j, Y', strtotime($b->expiry_date)) }}
                                    </span>
                                    @if($expSoon)
                                        <div class="small text-warning"><i class="ph-fill ph-clock"></i> Soon</div>
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
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">No active stock at this branch. Received deliveries appear here after confirmation.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>