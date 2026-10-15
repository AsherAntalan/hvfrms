@php
    $labels=['active'=>'Active Stock','expired'=>'Expired Stock','orders'=>'Pending Orders','disposal'=>'Disposal Records'];
    $countHead=['active'=>'Batches','expired'=>'Batches','orders'=>'Active Orders','disposal'=>'Records'];
    $secondHead=$kind==='orders'?'Awaiting Review':'Products';
    $thirdHead=$kind==='orders'?'Value':'Quantity by Unit';
@endphp
<div class="hv-inv-bar">
    <label class="visually-hidden" for="inventoryBranchSearch-{{ $kind }}">Search {{ $labels[$kind] }} by branch or product</label>
    <div class="hv-inv-search">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input id="inventoryBranchSearch-{{ $kind }}" type="search" data-kind="{{ $kind }}" oninput="filterInventoryBranches(this)"
               placeholder="Search branches{{ $kind === 'orders' ? ' or order #' : ' or products' }}…">
    </div>
    <span class="hv-inv-count">{{ $branchSummaries->count() }} branches · select one to expand {{ strtolower($labels[$kind]) }}</span>
</div>
<div class="table-responsive">
<table class="table align-middle mb-0 inventory-branch-table hv-inv" data-kind="{{ $kind }}">
    <thead><tr>
        <th class="ps-3">Branch</th>
        <th class="text-end hv-inv-numcol">{{ $countHead[$kind] }}</th>
        <th class="text-end hv-inv-numcol">{{ $secondHead }}</th>
        <th>{{ $thirdHead }}</th>
        <th class="text-end pe-3"><span class="visually-hidden">Details</span></th>
    </tr></thead>
    <tbody>
    @foreach($branchSummaries as $summary)
        @php
            $branch=$summary['branch']; $records=$summary[$kind];
            $detailId='inventory-'.$kind.'-branch-'.$branch->id;
            $searchText=strtolower($branch->branch_name.' '.$records->map(fn($record)=>$record->item_name ?? $record->order_number)->implode(' '));
            $typeLabel=match($branch->branch_type){'main'=>'Main Store','commissary'=>'Commissary',default=>'Franchise'};
            $isSource=in_array($branch->branch_type,['main','commissary'],true);
            $count=$records->count();
            $second=$kind==='orders'?$records->where('status','pending')->count():$records->pluck('item_id')->unique()->count();
            $hasData=$count>0;
        @endphp
        <tr class="inventory-branch-row hv-inv-row {{ $isSource ? 'is-source' : '' }} {{ $hasData ? '' : 'is-empty' }}"
            data-kind="{{ $kind }}" data-branch-id="{{ $branch->id }}" data-details="{{ $detailId }}" data-search="{{ $searchText }}"
            data-bs-toggle="collapse" data-bs-target="#{{ $detailId }}" aria-controls="{{ $detailId }}" aria-expanded="false" role="button" tabindex="0">
            <td class="ps-3">
                <span class="hv-inv-branch">
                    <i class="bi bi-chevron-right hv-inv-chev" aria-hidden="true"></i>
                    <span class="hv-inv-name">{{ $branch->branch_name }}</span>
                </span>
                <span class="hv-inv-type {{ $isSource ? 'is-source' : '' }}">{{ $typeLabel }}</span>@unless($branch->is_active)<span class="hv-inv-type is-off">Inactive</span>@endunless
            </td>
            <td class="text-end hv-inv-numcol"><span class="hv-inv-num {{ $count ? '' : 'is-zero' }}">{{ $count }}</span></td>
            <td class="text-end hv-inv-numcol"><span class="hv-inv-num {{ $second ? '' : 'is-zero' }}">{{ $second }}</span></td>
            <td>
                @if($kind === 'orders')
                    <span class="hv-inv-value {{ $records->count() ? '' : 'is-zero' }}">₱{{ number_format($records->sum(fn($order)=>$order->fulfilled_amount ?? $order->total_amount),2) }}</span>
                @else
                    <span class="hv-inv-qty {{ $hasData ? '' : 'is-zero' }}">{{ $summary[$kind.'_quantities'] }}</span>
                    @if($kind === 'active' && $summary['low_stock'])<span class="hv-inv-low"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>{{ $summary['low_stock'] }} low-stock</span>@endif
                @endif
            </td>
            <td class="text-end pe-3"><span class="hv-inv-view">View<i class="bi bi-chevron-right" aria-hidden="true"></i></span></td>
        </tr>
        <tr class="inventory-detail-row hv-inv-detail"><td colspan="5" class="p-0 border-0">
            <div class="collapse" id="{{ $detailId }}">
                <div class="hv-inv-panel">
                    <h6 class="hv-inv-panel-title">{{ $branch->branch_name }} <span>— {{ $labels[$kind] }}</span></h6>
                    @php
                        // Owner / Main Branch roles manage any branch's stock from the
                        // monitor; a branch-scoped viewer only their own branch.
                        $viewer = auth()->user();
                        $ownsBranch = $viewer->isOwner() || $viewer->isMainBranchRole() || (int)$branch->id === (int)$viewer->branch_id;
                    @endphp
                    @include('partials.inventory-'.$kind.'-table', [
                        'activeBatches'=>$summary['active'],'expiredBatches'=>$summary['expired'],'pendingOrders'=>$summary['orders'],'disposalRecords'=>$summary['disposal'],
                        'canEditBatches'=>$canEditBatches && $ownsBranch,
                        'canManageProducts'=>$canManageProducts && (int)$branch->id === (int)$mainBid,
                        'canDisposeBatches'=>($canDisposeBatches ?? false) && $ownsBranch,
                        'canArchiveBatches'=>($canArchiveBatches ?? false) && $ownsBranch,
                    ])
                </div>
            </div>
        </td></tr>
    @endforeach
    </tbody>
</table>
</div>
