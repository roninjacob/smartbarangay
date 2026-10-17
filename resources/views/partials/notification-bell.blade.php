@php($notificationRoute = auth()->user()->role->value.'.notifications.')
<div class="dropdown notification-dropdown">
    <button class="notification-bell btn" type="button" id="notification-bell" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-controls="notification-preview" aria-label="Notifications, {{ $notificationUnreadCount }} unread">
        <x-app-icon name="bell"/>
        @if($notificationUnreadCount > 0)<span class="notification-count" aria-hidden="true">{{ $notificationUnreadCount > 99 ? '99+' : $notificationUnreadCount }}</span>@endif
    </button>
    <div class="dropdown-menu dropdown-menu-end notification-preview" id="notification-preview" aria-labelledby="notification-bell">
        <div class="notification-preview-heading"><strong>Notifications</strong><span>{{ $notificationUnreadCount }} unread</span></div>
        <ul class="list-unstyled mb-0">
            @forelse($notificationLatest as $notification)
                <li>@include('notifications.item', ['compact' => true])</li>
            @empty
                <li class="p-3 text-muted">No notifications yet.</li>
            @endforelse
        </ul>
        <a class="notification-view-all" href="{{ route($notificationRoute.'index') }}">View all notifications <x-app-icon name="arrow"/></a>
    </div>
</div>
