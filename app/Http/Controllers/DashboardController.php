<?php

namespace App\Http\Controllers;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function resident(Request $request): View
    {
        $reservations = $request->user()->reservations()->getQuery();
        $counts = $this->statusCounts($reservations);

        return view('dashboards.resident', [
            ...$this->shellData($request),
            'counts' => $counts,
            'totalReservations' => array_sum($counts),
            'inProgress' => $counts[ReservationStatus::Pending->value]
                + $counts[ReservationStatus::UnderReview->value]
                + $counts[ReservationStatus::Approved->value],
            'recentReservations' => (clone $reservations)->with(['service', 'schedule'])
                ->latest()->orderByDesc('id')->limit(6)->get(),
        ]);
    }

    public function admin(Request $request): View
    {
        $counts = $this->statusCounts(Reservation::query());
        $today = now('Asia/Manila')->toDateString();

        return view('dashboards.admin', [
            ...$this->shellData($request),
            'counts' => $counts,
            'totalReservations' => array_sum($counts),
            'needsAttention' => $counts[ReservationStatus::Pending->value]
                + $counts[ReservationStatus::UnderReview->value],
            'totalResidents' => User::where('role', UserRole::Resident->value)->count(),
            'todayReservations' => Reservation::whereHas('schedule', fn (Builder $query) => $query->whereDate('date', $today))->count(),
            'recentReservations' => Reservation::with(['user', 'service', 'schedule'])
                ->latest()->orderByDesc('id')->limit(6)->get(),
        ]);
    }

    private function statusCounts(Builder $reservations): array
    {
        $totals = (clone $reservations)->select('status')->selectRaw('COUNT(*) AS total')
            ->groupBy('status')->pluck('total', 'status');
        $counts = [];

        foreach (ReservationStatus::cases() as $status) {
            $counts[$status->value] = (int) ($totals[$status->value] ?? 0);
        }

        return $counts;
    }

    private function shellData(Request $request): array
    {
        $role = $request->user()->role;

        return [
            'roleLabel' => $role === UserRole::Admin ? 'Admin' : 'Resident',
            'navigation' => config('navigation.'.$role->value),
            'dashboardDate' => now('Asia/Manila'),
            'statuses' => ReservationStatus::cases(),
        ];
    }
}
