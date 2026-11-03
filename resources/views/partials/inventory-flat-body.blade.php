@php
    // Swappable body for one flat All-Branches monitor tab: the cross-branch
    // table plus its pagination + filtered-count footer. The filter bar lives
    // outside this container so its inputs keep focus/value across AJAX swaps.
    $paginator = $paginators[$kind];
    $meta = $flatMeta[$kind];
@endphp
@include('partials.inventory-'.$kind.'-table', ['rows' => $paginator, 'showBranch' => true])
@include('partials.inventory-pagination', [
    'paginator' => $paginator,
    'kind' => $kind,
    'filteredTotal' => $meta['total'],
    'unitNote' => $meta['unitNote'],
])
