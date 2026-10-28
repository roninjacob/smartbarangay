@props(['groups', 'roleLabel'])
<nav class="app-navigation" aria-label="{{ $roleLabel }} navigation">
    @foreach($groups as $heading => $items)
        <div class="app-nav-group">
            <p class="app-nav-heading">{{ $heading }}</p>
            <ul class="list-unstyled mb-0">
                @foreach($items as $item)
                    <li>
                        @if(isset($item['route']))
                            <a href="{{ route($item['route']) }}" @class(['app-nav-link', 'is-current' => request()->routeIs($item['active'] ?? $item['route'])])
                                @if(request()->routeIs($item['active'] ?? $item['route'])) aria-current="page" @endif data-app-nav-link>
                                <span class="app-nav-icon" aria-hidden="true"><x-app-icon :name="$item['icon']"/></span><span class="app-nav-label">{{ $item['label'] }}</span>
                            </a>
                        @else
                            <span class="app-nav-link app-nav-unavailable" aria-disabled="true">
                                <span class="app-nav-icon" aria-hidden="true"><x-app-icon :name="$item['icon']"/></span><span class="app-nav-label">{{ $item['label'] }}</span><small>Soon</small>
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
    <div class="app-nav-group app-nav-account">
        <p class="app-nav-heading">Account</p>
        @php($profilePrefix = auth()->user()->role->value.'.profile')
        <a href="{{ route($profilePrefix.'.edit') }}" @class(['app-nav-link', 'is-current' => request()->routeIs($profilePrefix.'.*')]) @if(request()->routeIs($profilePrefix.'.*')) aria-current="page" @endif data-app-nav-link><span class="app-nav-icon" aria-hidden="true"><x-app-icon name="account"/></span><span class="app-nav-label">Profile / Account Settings</span></a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="app-nav-link app-logout w-100"><span class="app-nav-icon" aria-hidden="true"><x-app-icon name="logout"/></span><span class="app-nav-label">Logout</span></button>
        </form>
    </div>
</nav>
