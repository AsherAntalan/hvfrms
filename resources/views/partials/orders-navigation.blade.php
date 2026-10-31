<nav class="hv-orders-navigation" aria-label="Orders views">
    @if(Auth::user()->isBranchManager())
    <a href="{{ route('ordering') }}" data-orders-view="supplies"
       class="hv-orders-navigation-link {{ request()->routeIs('ordering*') ? 'active' : '' }}"
       @if(request()->routeIs('ordering*')) aria-current="page" @endif>
        Order Supplies
    </a>
    @endif
    <a href="{{ route('orders') }}" data-orders-view="all"
       class="hv-orders-navigation-link {{ request()->routeIs('orders*') ? 'active' : '' }}"
       @if(request()->routeIs('orders*')) aria-current="page" @endif>
        All Orders
    </a>
    <a href="{{ route('delivery') }}" data-orders-view="delivery"
       class="hv-orders-navigation-link {{ request()->routeIs('delivery*') ? 'active' : '' }}"
       @if(request()->routeIs('delivery*')) aria-current="page" @endif>
        Delivery Status
    </a>
</nav>
