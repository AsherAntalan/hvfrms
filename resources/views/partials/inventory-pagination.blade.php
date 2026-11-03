@php
    $current = $paginator->currentPage();
    $last = $paginator->lastPage();
    $window = 2;
    $start = max(1, $current - $window);
    $end = min($last, $current + $window);
    $shownFrom = $paginator->count() ? $paginator->firstItem() : 0;
    $shownTo = $paginator->count() ? $paginator->lastItem() : 0;
@endphp
<div class="hv-inv-pager-wrap d-flex flex-wrap align-items-center justify-content-between gap-2 px-3 py-2 border-top">
    <div class="small text-muted" role="status" aria-live="polite">
        @if($filteredTotal > 0)
            Showing {{ $shownFrom }}–{{ $shownTo }} of {{ number_format($filteredTotal) }} match{{ $filteredTotal === 1 ? '' : 'es' }}
        @else
            No matching records
        @endif
        @if($unitNote ?? false)
            <span class="ms-2 text-warning"><i class="ph ph-info" aria-hidden="true"></i> Quantities compared within each unit.</span>
        @endif
    </div>
    @if($last > 1)
    <nav class="hv-inv-pager" aria-label="{{ ucfirst($kind) }} pagination">
        <button type="button" class="hv-page-btn" data-kind="{{ $kind }}" data-page="{{ $current - 1 }}" {{ $current <= 1 ? 'disabled' : '' }}>
            <i class="ph ph-caret-left" aria-hidden="true"></i> Prev
        </button>
        @if($start > 1)
            <button type="button" class="hv-page-btn" data-kind="{{ $kind }}" data-page="1">1</button>
            @if($start > 2)<span class="hv-page-gap" aria-hidden="true">…</span>@endif
        @endif
        @for($p = $start; $p <= $end; $p++)
            <button type="button" class="hv-page-btn {{ $p === $current ? 'active' : '' }}" data-kind="{{ $kind }}" data-page="{{ $p }}"
                    {{ $p === $current ? 'aria-current=page' : '' }}>{{ $p }}</button>
        @endfor
        @if($end < $last)
            @if($end < $last - 1)<span class="hv-page-gap" aria-hidden="true">…</span>@endif
            <button type="button" class="hv-page-btn" data-kind="{{ $kind }}" data-page="{{ $last }}">{{ $last }}</button>
        @endif
        <button type="button" class="hv-page-btn" data-kind="{{ $kind }}" data-page="{{ $current + 1 }}" {{ $current >= $last ? 'disabled' : '' }}>
            Next <i class="ph ph-caret-right" aria-hidden="true"></i>
        </button>
    </nav>
    @endif
</div>
