<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Reservation;
use App\Services\ReservationNotifications;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['filter' => ['nullable', 'in:all,unread']]);
        $filter = $filters['filter'] ?? 'all';
        $user = $request->user();

        return view('notifications.index', [
            'roleLabel' => $user->role === UserRole::Admin ? 'Admin' : 'Resident',
            'navigation' => config('navigation.'.$user->role->value), 'dashboardDate' => now('Asia/Manila'),
            'pageHeading' => 'Notifications', 'pageTitle' => 'Notifications', 'pageSection' => 'Your Account',
            'filter' => $filter,
            'notifications' => $user->notifications()->when($filter === 'unread', fn ($query) => $query->whereNull('read_at'))
                ->reorder()->orderByDesc('created_at')->orderByDesc('id')->paginate(15)->withQueryString(),
        ]);
    }

    public function open(Request $request, string $notification): RedirectResponse
    {
        $owned = $this->owned($request, $notification);
        $owned->markAsRead();
        $data = $owned->data;
        $id = $data['reservation_id'] ?? null;
        $user = $request->user();
        if (in_array($owned->type, ReservationNotifications::typesFor($user), true) && is_int($id) && $id > 0) {
            $target = Reservation::whereKey($id)
                ->when($user->role === UserRole::Resident, fn ($query) => $query->where('user_id', $user->id))->first(['id']);
            if ($target) {
                return to_route($user->role->value.'.reservations.show', $target);
            }
        }

        return to_route($user->role->value.'.notifications.index')->with('status', 'This request is no longer available. Your notification has been kept in history.');
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        $this->owned($request, $notification)->markAsRead();

        return to_route($request->user()->role->value.'.notifications.index')->with('status', 'Notification marked as read.');
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return to_route($request->user()->role->value.'.notifications.index')->with('status', 'All your notifications are marked as read.');
    }

    private function owned(Request $request, string $id): DatabaseNotification
    {
        return $request->user()->notifications()->whereKey($id)->firstOrFail();
    }
}
