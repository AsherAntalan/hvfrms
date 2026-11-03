@php
    // Filter/sort controls for one flat All-Branches monitor tab. Stays outside
    // the swappable body so inputs keep their value and focus across AJAX reloads.
    $searchPlaceholder = match ($kind) {
        'orders' => 'Search order #, branch, or requester…',
        'disposal' => 'Search product, branch, or reason…',
        default => 'Search product, branch, or batch…',
    };
    $sortOptions = match ($kind) {
        'active' => ['product_asc' => 'Product A–Z', 'product_desc' => 'Product Z–A', 'qty_desc' => 'Quantity Highest–Lowest', 'qty_asc' => 'Quantity Lowest–Highest', 'expiry_near' => 'Nearest Expiry'],
        'expired' => ['expiry_recent' => 'Most Recently Expired', 'expiry_oldest' => 'Oldest Expiry', 'qty_desc' => 'Quantity Highest–Lowest', 'qty_asc' => 'Quantity Lowest–Highest'],
        'disposal' => ['newest' => 'Newest First', 'oldest' => 'Oldest First'],
        'orders' => ['newest' => 'Newest First', 'oldest' => 'Oldest First', 'value_desc' => 'Value Highest–Lowest', 'value_asc' => 'Value Lowest–Highest'],
        default => [],
    };
@endphp
<div class="hv-inv-filter p-3 border-bottom" data-kind="{{ $kind }}">
    <div class="row g-2 align-items-center">
        <div class="col-12 col-lg">
            <input type="search" class="form-control form-control-sm" id="flt-{{ $kind }}-q"
                   placeholder="{{ $searchPlaceholder }}" aria-label="{{ $searchPlaceholder }}"
                   oninput="debounceFlatFilter('{{ $kind }}')">
        </div>

        <div class="col-6 col-md-4 col-lg-auto">
            <select class="form-select form-select-sm" id="flt-{{ $kind }}-branch" aria-label="Filter by branch" onchange="applyFlatFilter('{{ $kind }}')">
                <option value="">All Branches</option>
                @foreach($filterBranches as $br)
                    <option value="{{ $br->id }}">{{ $br->branch_name }}</option>
                @endforeach
            </select>
        </div>

        @if(in_array($kind, ['active', 'expired', 'disposal'], true))
        <div class="col-6 col-md-4 col-lg-auto">
            <select class="form-select form-select-sm" id="flt-{{ $kind }}-product" aria-label="Filter by product" onchange="applyFlatFilter('{{ $kind }}')">
                <option value="">All Products</option>
                @foreach($filterProducts as $p)
                    <option value="{{ $p->id }}">{{ $p->item_name }}</option>
                @endforeach
            </select>
        </div>
        @endif

        @if(in_array($kind, ['active', 'expired'], true))
        <div class="col-6 col-md-4 col-lg-auto">
            <select class="form-select form-select-sm" id="flt-{{ $kind }}-unit" aria-label="Filter by unit" onchange="applyFlatFilter('{{ $kind }}')">
                <option value="">All Units</option>
                @foreach($filterUnits as $u)
                    <option value="{{ $u }}">{{ $u }}</option>
                @endforeach
            </select>
        </div>
        @endif

        @if($kind === 'active')
        <div class="col-6 col-md-4 col-lg-auto">
            <select class="form-select form-select-sm" id="flt-{{ $kind }}-status" aria-label="Filter by stock status" onchange="applyFlatFilter('{{ $kind }}')">
                <option value="">All Statuses</option>
                <option value="low">Low Stock</option>
                <option value="out">Out of Stock</option>
                <option value="above">Above Reorder Level</option>
            </select>
        </div>
        @endif

        @if($kind === 'orders')
        <div class="col-6 col-md-4 col-lg-auto">
            <select class="form-select form-select-sm" id="flt-{{ $kind }}-status" aria-label="Filter by order status" onchange="applyFlatFilter('{{ $kind }}')">
                <option value="">All Statuses</option>
                @foreach($orderStatuses as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        @endif

        @if($kind === 'disposal')
        <div class="col-6 col-md-auto col-lg-auto">
            <input type="date" class="form-control form-control-sm" id="flt-{{ $kind }}-from" aria-label="Disposed from date" onchange="applyFlatFilter('{{ $kind }}')">
        </div>
        <div class="col-6 col-md-auto col-lg-auto">
            <input type="date" class="form-control form-control-sm" id="flt-{{ $kind }}-to" aria-label="Disposed to date" onchange="applyFlatFilter('{{ $kind }}')">
        </div>
        @endif

        <div class="col-6 col-md-4 col-lg-auto">
            <select class="form-select form-select-sm" id="flt-{{ $kind }}-sort" aria-label="Sort records" onchange="applyFlatFilter('{{ $kind }}')">
                @foreach($sortOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="col-auto">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearFlatFilters('{{ $kind }}')">
                <i class="ph ph-x" aria-hidden="true"></i> Clear Filters
            </button>
        </div>
    </div>
</div>
