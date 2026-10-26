@extends('layouts.authenticated')
@section('dashboard')
<section class="notification-history" aria-label="Notification history">
    <div class="notification-toolbar">
        <nav class="notification-filters" aria-label="Filter notifications">
            <a href="{{ route(auth()->user()->role->value.'.notifications.index', ['filter' => 'all']) }}" class="btn {{ $filter === 'all' ? 'btn-primary' : 'btn-outline-primary' }}" @if($filter === 'all') aria-current="page" @endif>All</a>
            <a href="{{ route(auth()->user()->role->value.'.notifications.index', ['filter' => 'unread']) }}" class="btn {{ $filter === 'unread' ? 'btn-primary' : 'btn-outline-primary' }}" @if($filter === 'unread') aria-current="page" @endif>Unread</a>
        </nav>
        <form method="POST" action="{{ route(auth()->user()->role->value.'.notifications.read-all') }}">@csrf<button type="submit" class="btn btn-outline-secondary">Mark all as read</button></form>
    </div>
    <p class="text-muted">Updates about your {{ auth()->user()->role->value === 'admin' ? 'Residents’ requests' : 'requests' }}. Read notifications stay in your history.</p>
    <ul class="notification-list list-unstyled">
        @forelse($notifications as $notification)
            <li>@include('notifications.item', ['compact' => false])</li>
        @empty
            <li class="notification-empty"><x-app-icon name="bell"/><h2>{{ $filter === 'unread' ? "You're all caught up." : 'No notifications yet.' }}</h2><p>{{ $filter === 'unread' ? 'You have no unread notifications. Your read updates remain in All.' : 'New request updates will appear here.' }}</p></li>
        @endforelse
    </ul>
    {{ $notifications->links('pagination.civic', ['showSummary' => true]) }}
</section>
@endsection
