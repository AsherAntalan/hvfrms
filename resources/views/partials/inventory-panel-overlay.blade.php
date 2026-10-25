{{-- Reusable loading overlay for an inventory tab panel. $panel = active|expired|orders|disposal --}}
<div class="hv-panel-overlay" data-overlay="{{ $panel }}" hidden aria-hidden="true">
    <div class="hv-panel-overlay-box">
        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
        <span class="hv-panel-overlay-text">Loading…</span>
    </div>
</div>
