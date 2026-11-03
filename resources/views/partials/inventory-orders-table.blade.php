@php
    $rows = $rows ?? $pendingOrders;
    $showBranch = $showBranch ?? false;
@endphp
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Order #</th>
                            <th>Branch</th>
                            <th>Ordered By</th>
                            <th>Items</th>
                            <th class="text-end">Total</th>
                            <th>Date</th>
                            <th class="text-center">Status</th>
                            @if($canFulfillOrders)
                            <th class="text-center">Actions</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($rows as $o)
                        <tr>
                            <td class="ps-3 fw-bold" style="color:var(--maroon);">{{ $o->order_number }}</td>
                            <td>{{ $o->branch_name }}</td>
                            <td class="small">{{ $o->ordered_by_name ?? '—' }}</td>
                            <td class="small">
                                @if(isset($orderItems[$o->id]))
                                    @foreach($orderItems[$o->id] as $oi)
                                        <div>{{ $oi->quantity }}x {{ $oi->item_name }} <span class="text-muted">({{ $oi->unit }})</span></div>
                                    @endforeach
                                @else
                                    <span class="text-muted">&mdash;</span>
                                @endif
                            </td>
                            <td class="text-end fw-semibold">&#8369;{{ number_format($o->fulfilled_amount ?? $o->total_amount, 2) }}</td>
                            <td class="small text-muted">{{ \Carbon\Carbon::parse($o->created_at)->format('M j, g:i A') }}</td>
                            <td class="text-center">
                                @php
                                    $statusBadge = match($o->status) {
                                        'pending' => 'hv-badge-warning',
                                        'approved' => 'hv-badge-info',
                                        'delivering' => 'hv-badge-info',
                                        default => 'hv-badge-secondary',
                                    };
                                @endphp
                                <span class="hv-badge {{ $statusBadge }}">{{ \App\Services\OrderLabelService::stageLabel($o) }}</span>
                            </td>
                            @if($canFulfillOrders)
                            <td class="text-center">
                                <div class="d-flex gap-1 justify-content-center flex-wrap">
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('orders', ['queue' => 'all', 'order' => $o->id]) }}">Manage in Orders</a>

                                </div>
                            </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $canFulfillOrders ? 8 : 7 }}" class="text-center text-muted py-4">{{ $showBranch ? 'No pending orders match these filters. Try clearing them.' : 'No pending orders from branches.' }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
