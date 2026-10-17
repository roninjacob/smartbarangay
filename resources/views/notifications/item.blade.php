@php
    $content = $notification->data;
    $unread = $notification->read_at === null;
    $notificationRoute = auth()->user()->role->value.'.notifications.';
    $category = $content['category'] ?? 'status_update';
    $icon = match($category) { 'success', 'completion', 'confirmation' => 'check', 'payment' => 'document', 'action_required', 'important_status' => 'bell', default => 'status' };
@endphp
<article class="notification-item {{ $unread ? 'is-unread' : '' }} {{ $compact ? 'is-compact' : '' }}">
    <span class="notification-symbol" aria-hidden="true"><x-app-icon :name="$icon"/></span>
    <div class="notification-body">
        <div class="notification-title"><h2>{{ $content['title'] ?? 'Notification' }}</h2>@if($unread)<span class="notification-unread">Unread</span>@else<span class="notification-read">Read</span>@endif</div>
        <p>{{ $content['message'] ?? 'Open this notification to review your request.' }}</p>
        <time datetime="{{ $notification->created_at->toIso8601String() }}">{{ $notification->created_at->copy()->timezone('Asia/Manila')->format('M j, Y · g:i A') }} PHT</time>
        <div class="notification-actions">
            <form method="POST" action="{{ route($notificationRoute.'open', $notification->id) }}">@csrf<button class="btn btn-sm {{ $compact ? 'btn-link' : 'btn-outline-primary' }}" type="submit" aria-label="Open {{ $content['title'] ?? 'notification' }}">Open request <x-app-icon name="arrow"/></button></form>
            @if($unread && !$compact)<form method="POST" action="{{ route($notificationRoute.'read', $notification->id) }}">@csrf<button class="btn btn-sm btn-light" type="submit" aria-label="Mark {{ $content['title'] ?? 'notification' }} as read">Mark as read</button></form>@endif
        </div>
    </div>
</article>
