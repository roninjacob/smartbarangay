@props(['groups', 'roleLabel'])
<nav class="app-navigation" aria-label="{{ $roleLabel }} navigation">
    @foreach($groups as $heading => $items)
        <div class="app-nav-group">
            <p class="app-nav-heading">{{ $heading }}</p>
            <ul class="list-unstyled mb-0">
                @foreach($items as $item)
                    <li>
                        @if(isset($item['route']))
                            <a href="{{ route($item['route']) }}" @class(['app-nav-link', 'is-current' => request()->routeIs($item['route'])])
                                @if(request()->routeIs($item['route'])) aria-current="page" @endif data-app-nav-link>
                                <x-app-icon :name="$item['icon']"/><span>{{ $item['label'] }}</span>
                            </a>
                        @else
                            <span class="app-nav-link app-nav-unavailable" aria-disabled="true">
                                <x-app-icon :name="$item['icon']"/><span>{{ $item['label'] }}</span><small>Soon</small>
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
    <div class="app-nav-group">
        <p class="app-nav-heading">Account</p>
        <button type="button" class="app-nav-link w-100" data-bs-toggle="modal" data-bs-target="#account-summary" data-app-account>
            <x-app-icon name="account"/><span>Profile / Account Settings</span>
        </button>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="app-nav-link app-logout w-100"><x-app-icon name="logout"/><span>Logout</span></button>
        </form>
    </div>
</nav>
