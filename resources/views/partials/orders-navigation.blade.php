{{-- Branch managers switch between placing supply orders and reviewing all
     orders, so they keep a two-tab bar. Every other role lands on a single
     Orders view, so the tab bar is omitted. Active Shipments is reached from
     the Active Shipments button in the Orders page header, not a top tab. --}}
@if(Auth::user()->isBranchManager())
<nav class="hv-orders-navigation" aria-label="Orders views">
    <a href="{{ route('ordering') }}" data-orders-view="supplies"
       class="hv-orders-navigation-link {{ request()->routeIs('ordering*') ? 'active' : '' }}"
       @if(request()->routeIs('ordering*')) aria-current="page" @endif>
        Order Supplies
    </a>
    <a href="{{ route('orders') }}" data-orders-view="all"
       class="hv-orders-navigation-link {{ request()->routeIs('orders*') ? 'active' : '' }}"
       @if(request()->routeIs('orders*')) aria-current="page" @endif>
        All Orders
    </a>
</nav>
@endif
