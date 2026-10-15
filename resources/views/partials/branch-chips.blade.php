{{--
    Shared branch-filter chips — Reports, Forecast & DSS, Alerts.
    Server-rendered links (page navigation); the selected branch is carried in
    the URL so it is the single authoritative scope, survives refresh, and is
    always enforced server-side. No modal is opened by these chips.

    Expected variables (pass via @include):
      chipRoute    string  route name to navigate to
      chipBranches iterable of branch rows (id, branch_name, branch_type)
      chipSelected string|int  currently selected branch value
      chipAllValue string|int  value meaning "All Branches" (e.g. 0 or '')
      chipAllowAll bool    show the All Branches chip
      chipMainId   int|null id of the main warehouse (for the label)
      chipPreserve array   extra query params to keep (dates, filters…)
      chipCounts   optional map id => count (shown as a badge)
      chipLabel    optional heading text (default: Branch)
--}}
@php
    $chipPreserve = $chipPreserve ?? [];
    $chipCounts   = $chipCounts ?? null;
    $sel          = (string) ($chipSelected ?? '');
@endphp
<div class="mb-1 small text-muted fw-semibold">{{ $chipLabel ?? 'Branch' }}</div>
<div class="hv-branch-chips" role="group" aria-label="Branch filter">
    @if(!empty($chipAllowAll))
        @php $allVal = (string) ($chipAllValue ?? '0'); @endphp
        <a href="{{ route($chipRoute, array_merge($chipPreserve, ['branch' => $chipAllValue ?? '0'])) }}"
           class="hv-branch-chip {{ $sel === $allVal ? 'active' : '' }}"
           @if($sel === $allVal) aria-current="true" @endif>
            <i class="bi bi-grid-3x3-gap me-1"></i>All Branches
            @if($chipCounts !== null && $chipCounts->sum() > 0)
                <span class="badge bg-secondary ms-1" style="font-size:10px;">{{ $chipCounts->sum() }}</span>
            @endif
        </a>
    @endif
    @foreach($chipBranches as $br)
        @php
            $isMain = isset($chipMainId) && (int) $br->id === (int) $chipMainId;
            $active = $sel === (string) $br->id;
        @endphp
        <a href="{{ route($chipRoute, array_merge($chipPreserve, ['branch' => $br->id])) }}"
           class="hv-branch-chip {{ $active ? 'active' : '' }}"
           @if($active) aria-current="true" @endif>
            @if($isMain)<i class="bi bi-building-fill-gear me-1"></i>@endif
            {{ $br->branch_name }}@if($isMain) · Main Warehouse @endif
            @if($chipCounts !== null)
                @php $c = $chipCounts->get($br->id, 0); @endphp
                @if($c > 0)<span class="badge bg-secondary ms-1" style="font-size:10px;">{{ $c }}</span>@endif
            @endif
        </a>
    @endforeach
</div>
