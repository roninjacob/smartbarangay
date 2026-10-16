<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\ReservationPayment;
use Brick\Math\BigDecimal;

class PaymentCollections
{
    public function summary(): array
    {
        $local = now('Asia/Manila')->toImmutable();
        $today = $local->startOfDay()->utc()->format('Y-m-d H:i:s');
        $tomorrow = $local->startOfDay()->addDay()->utc()->format('Y-m-d H:i:s');
        $month = $local->startOfMonth()->utc()->format('Y-m-d H:i:s');
        $nextMonth = $local->startOfMonth()->addMonth()->utc()->format('Y-m-d H:i:s');
        $totals = ReservationPayment::consistent()->where('paid_at', '>=', $month)->where('paid_at', '<', $nextMonth)
            ->selectRaw('COALESCE(SUM(CASE WHEN paid_at >= ? AND paid_at < ? THEN amount ELSE 0 END), 0) AS today_amount,
                COALESCE(SUM(amount), 0) AS month_amount,
                COALESCE(SUM(CASE WHEN paid_at >= ? AND paid_at < ? THEN 1 ELSE 0 END), 0) AS today_count', [$today, $tomorrow, $today, $tomorrow])->first();

        return ['today' => (string) BigDecimal::of((string) $totals->today_amount)->toScale(2),
            'month' => (string) BigDecimal::of((string) $totals->month_amount)->toScale(2),
            'paid_today' => (int) $totals->today_count,
            'ready_unpaid' => Reservation::where('status', ReservationStatus::ReadyForPickup)->where('fee_snapshot', '>', 0)->whereDoesntHave('payment')->count(),
            'integrity_issues' => ReservationPayment::whereNotIn('id', ReservationPayment::consistent()->select('id'))->count()];
    }
}
