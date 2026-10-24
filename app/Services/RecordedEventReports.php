<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class RecordedEventReports
{
    public function data(string $kind, array $filters, array $period): array
    {
        if (! in_array($kind, ['checkins', 'documents'], true)) {
            throw new \InvalidArgumentException('Unknown recorded event report.');
        }

        return DB::transaction(function () use ($kind, $filters, $period) {
            $ids = app(ReportingExclusions::class)->validatedIds();
            $queries = app(ReportQueries::class);
            $parts = $queries->build($kind, $period, $ids);
            $base = $parts['base'];
            $valid = $parts['valid'];
            $time = $kind === 'documents' ? 'generated_at' : 'verified_at';
            $excluded = (clone $parts['population'])->whereIn($parts['reservation_key'], $ids)->count();
            $issues = (clone $parts['invalid'])->count();
            $count = (clone $valid)->count();
            if ((clone $base)->count() !== $count + $issues) {
                throw new RuntimeException(ReportingExclusions::UNAVAILABLE);
            }
            $days = (int) $period['start']->diffInDays($period['end']) + 1;
            $bucket = $days === 1 ? 'hour' : ($days <= 93 ? 'day' : ($days <= 731 ? 'month' : 'year'));
            $format = ['hour' => 'Y-m-d H:00', 'day' => 'Y-m-d', 'month' => 'Y-m', 'year' => 'Y'][$bucket];
            $sqlFormat = ['hour' => '%Y-%m-%d %H:00', 'day' => '%Y-%m-%d', 'month' => '%Y-%m', 'year' => '%Y'][$bucket];
            $expression = DB::getDriverName() === 'sqlite'
                ? "strftime('$sqlFormat', event.$time, '+8 hours')"
                : "DATE_FORMAT(DATE_ADD(event.$time, INTERVAL 8 HOUR), '$sqlFormat')";
            $grouped = (clone $valid)->selectRaw("$expression AS bucket, COUNT(*) AS total")->groupByRaw($expression)->pluck('total', 'bucket');
            $cursor = match ($bucket) {
                'month' => $period['start']->startOfMonth(), 'year' => $period['start']->startOfYear(), default => $period['start'],
            };
            $series = [];
            while ($cursor <= $period['end']->endOfDay()) {
                $series[] = ['label' => $cursor->format($format), 'count' => (int) ($grouped[$cursor->format($format)] ?? 0)];
                $cursor = match ($bucket) {
                    'hour' => $cursor->addHour(), 'day' => $cursor->addDay(), 'month' => $cursor->addMonth(), 'year' => $cursor->addYear(),
                };
            }
            $services = (clone $valid)->select('s.id as service_id', 's.name')->selectRaw('COUNT(*) AS count')
                ->groupBy('s.id', 's.name')->orderByDesc('count')->orderBy('s.id')->get()
                ->map(fn ($row) => ['service_id' => $row->service_id, 'name' => $row->name, 'count' => (int) $row->count])->all();
            $ranking = array_slice($services, 0, 5);
            if (count($services) > 5) {
                $ranking[] = ['service_id' => null, 'name' => 'Other services', 'count' => array_sum(array_column(array_slice($services, 5), 'count'))];
            }
            $peak = max([1, ...array_column($series, 'count')]);
            $points = [];
            foreach ($series as $index => $row) {
                $points[] = round(55 + $index * 590 / max(1, count($series) - 1), 4).','.round(215 - $row['count'] * 170 / $peak, 4);
            }
            $maximum = max([1, ...array_column($ranking, 'count')]);
            foreach ($ranking as &$row) {
                $row['width'] = round($row['count'] * 100 / $maximum, 4);
            }
            unset($row);
            // Explicit projection: never load QR credentials, paths, snapshots, notes or passwords.
            $events = $queries->details($kind, $valid)
                ->orderByDesc('event.'.$time)->orderByDesc('event.id')->paginate(15)->appends($filters);
            foreach ([$series, $services, $ranking] as $rows) {
                if (array_sum(array_column($rows, 'count')) !== $count) {
                    throw new RuntimeException(ReportingExclusions::UNAVAILABLE);
                }
            }
            if ($events->total() !== $count) {
                throw new RuntimeException(ReportingExclusions::UNAVAILABLE);
            }

            return compact('count', 'issues', 'excluded', 'series', 'bucket', 'peak', 'services', 'ranking', 'events')
                + ['points' => implode(' ', $points)];
        });
    }
}
