<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReservationReports
{
    public function period(array $filters): array
    {
        $today = CarbonImmutable::now('Asia/Manila')->startOfDay();
        [$start, $end] = match ($filters['period'] ?? 'month') {
            'today' => [$today, $today],
            'yesterday' => [$today->subDay(), $today->subDay()],
            'week' => [$today->startOfWeek(1), $today->startOfWeek(1)->addDays(6)],
            'date' => [$this->day($filters['date']), $this->day($filters['date'])],
            'custom' => [$this->day($filters['start']), $this->day($filters['end'])],
            default => [$today->startOfMonth(), $today->endOfMonth()->startOfDay()],
        };

        return ['start' => $start, 'end' => $end, 'from' => $start->utc()->format('Y-m-d H:i:s'),
            'until' => $end->addDay()->utc()->format('Y-m-d H:i:s')];
    }

    private function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Asia/Manila');
    }

    public function data(array $filters, array $period): array
    {
        // A consistent read snapshot keeps validation, counts and pagination in agreement.
        return DB::transaction(function () use ($filters, $period) {
            $ids = app(ReportingExclusions::class)->validatedIds();
            $population = Reservation::where('reservations.created_at', '>=', $period['from'])
                ->where('reservations.created_at', '<', $period['until']);
            $excluded = (clone $population)->whereIn('reservations.id', $ids)->count();
            $base = (clone $population)->whereNotIn('reservations.id', $ids);
            $grouped = (clone $base)->select('status')->selectRaw('COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
            $counts = [];
            foreach (ReservationStatus::cases() as $status) {
                $counts[$status->value] = (int) ($grouped[$status->value] ?? 0);
            }
            if (count(array_diff(array_keys($grouped->all()), array_keys($counts))) > 0) {
                throw new RuntimeException(ReportingExclusions::UNAVAILABLE);
            }
            $days = (int) $period['start']->diffInDays($period['end']) + 1;
            // Bound chart size for long ranges without truncating the reporting population.
            $bucket = $days === 1 ? 'hour' : ($days <= 93 ? 'day' : ($days <= 731 ? 'month' : 'year'));
            $format = ['hour' => 'Y-m-d H:00', 'day' => 'Y-m-d', 'month' => 'Y-m', 'year' => 'Y'][$bucket];
            $sqlFormat = ['hour' => '%Y-%m-%d %H:00', 'day' => '%Y-%m-%d', 'month' => '%Y-%m', 'year' => '%Y'][$bucket];
            $expression = DB::getDriverName() === 'sqlite'
                ? "strftime('$sqlFormat', reservations.created_at, '+8 hours')"
                : "DATE_FORMAT(DATE_ADD(reservations.created_at, INTERVAL 8 HOUR), '$sqlFormat')";
            $totals = (clone $base)->selectRaw("$expression AS bucket, COUNT(*) AS total")->groupByRaw($expression)->pluck('total', 'bucket');
            $cursor = match ($bucket) {
                'month' => $period['start']->startOfMonth(), 'year' => $period['start']->startOfYear(), default => $period['start'],
            };
            $series = [];
            while ($cursor <= $period['end']->endOfDay()) {
                $key = $cursor->format($format);
                $series[] = ['label' => $key, 'count' => (int) ($totals[$key] ?? 0)];
                $cursor = match ($bucket) {
                    'hour' => $cursor->addHour(), 'day' => $cursor->addDay(), 'month' => $cursor->addMonth(), 'year' => $cursor->addYear(),
                };
            }
            $services = (clone $base)->join('services', 'services.id', '=', 'reservations.service_id')
                ->select('services.id', 'services.name')->selectRaw('COUNT(*) AS total')->groupBy('services.id', 'services.name')
                ->orderByDesc('total')->orderBy('services.id')->limit(5)->get();

            return ['total' => array_sum($counts), 'counts' => $counts, 'excluded' => $excluded,
                'series' => $series, 'bucket' => $bucket, 'services' => $services,
                'reservations' => (clone $base)->with(['user:id,name', 'service:id,name'])
                    ->orderByDesc('reservations.created_at')->orderByDesc('reservations.id')->paginate(15)->appends($filters)];
        });
    }
}
