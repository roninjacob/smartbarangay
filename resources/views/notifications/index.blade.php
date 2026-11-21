@extends('layouts.authenticated')
@section('dashboard')
@php
    $notificationRoute = auth()->user()->role->value.'.notifications.';
    $groups = $notifications->getCollection()->groupBy(fn ($n) => $n->created_at->copy()->timezone('Asia/Manila')->isToday() ? 'Today' : 'Earlier');
@endphp
<x-page-intro subtitle="Updates about your {{ auth()->user()->role->value === 'admin' ? 'Residents’ requests' : 'requests' }}. Read notifications stay in your history."/>
<section class="dashboard-panel notification-history" aria-label="Notification history">
    <div class="ac-notif-toolbar">
        <nav aria-label="Filter notifications">
            <ul class="rv-chips">
                <li><a class="rv-chip" href="{{ route($notificationRoute.'index', ['filter' => 'all']) }}" @if($filter === 'all') aria-current="page" @endif>All</a></li>
                <li><a class="rv-chip" href="{{ route($notificationRoute.'index', ['filter' => 'unread']) }}" @if($filter === 'unread') aria-current="page" @endif><span class="rv-chip-dot" aria-hidden="true"></span>Unread</a></li>
            </ul>
        </nav>
        <form method="POST" action="{{ route($notificationRoute.'read-all') }}">@csrf<button type="submit" class="btn btn-outline-secondary btn-sm"><x-app-icon name="check"/> Mark all as read</button></form>
    </div>
    @forelse($groups as $label => $items)
        <div class="ac-notif-group">
            <h2>{{ $label }}</h2>
            <ul class="ac-notif-list">
                @foreach($items as $notification)
                    <li>@include('notifications.item', ['compact' => false])</li>
                @endforeach
            </ul>
        </div>
    @empty
        <div class="notification-empty">
            <span class="service-empty-icon"><x-app-icon name="bell"/></span>
            <h2>{{ $filter === 'unread' ? "You're all caught up." : 'No notifications yet.' }}</h2>
            <p>{{ $filter === 'unread' ? 'You have no unread notifications. Your read updates remain in All.' : 'New request updates will appear here.' }}</p>
        </div>
    @endforelse
    <div class="ac-notif-pagination">{{ $notifications->links('pagination.civic', ['showSummary' => true]) }}</div>
</section>
@endsection
