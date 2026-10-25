@php($links = [
    ['label' => 'Home', 'route' => 'resident.home', 'active' => 'resident.home', 'icon' => 'dashboard'],
    ['label' => 'Requests', 'route' => 'resident.reservations.index', 'active' => ['resident.reservations.*', 'resident.request-status.*'], 'icon' => 'document'],
    ['label' => 'QR tickets', 'route' => 'resident.qr-tickets.index', 'active' => 'resident.qr-tickets.*', 'icon' => 'qr'],
    ['label' => 'Account', 'route' => 'resident.profile.edit', 'active' => ['resident.profile.*', 'resident.settings.*'], 'icon' => 'account'],
])
<nav class="resident-mobile-nav" aria-label="Resident quick navigation">
    @foreach($links as $link)
        <a href="{{ route($link['route']) }}" @class(['resident-mobile-link', 'is-current' => request()->routeIs($link['active'])]) @if(request()->routeIs($link['active'])) aria-current="page" @endif><x-app-icon :name="$link['icon']"/><span>{{ $link['label'] }}</span></a>
    @endforeach
</nav>
