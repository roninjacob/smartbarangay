<?php

namespace App\Services;

use App\Models\CheckinLog;
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
            $documents = $kind === 'documents';
            $table = $documents ? 'reservation_documents' : 'checkin_logs';
            $time = $documents ? 'generated_at' : 'verified_at';
            $actor = $documents ? 'generated_by' : 'verified_by';
            $population = DB::table($table.' as event')->where('event.'.$time, '>=', $period['from'])
                ->where('event.'.$time, '<', $period['until']);
            if (! $documents) {
                $population->where('event.verification_result', CheckinLog::ARRIVAL);
            }
            $excluded = (clone $population)->whereIn('event.reservation_id', $ids)->count();
            // NULL reservation IDs must remain visible as warnings, not disappear in NOT IN.
            $base = (clone $population)->where(fn ($q) => $q->whereNull('event.reservation_id')->orWhereNotIn('event.reservation_id', $ids))
                ->leftJoin('reservations as r', 'r.id', '=', 'event.reservation_id')
                ->leftJoin('services as s', 's.id', '=', 'r.service_id')
                ->leftJoin('users as resident', 'resident.id', '=', 'r.user_id')
                ->leftJoin('schedules as schedule', 'schedule.id', '=', 'r.schedule_id');
            $validity = 'r.id IS NOT NULL AND s.id IS NOT NULL AND resident.id IS NOT NULL AND schedule.id IS NOT NULL';
            if ($documents) {
                $hex = DB::getDriverName() === 'sqlite'
                    ? "event.content_hash NOT GLOB '*[^0-9a-f]*' AND event.template_revision NOT GLOB '*[^0-9a-f]*'"
                    : "BINARY event.content_hash NOT REGEXP '[^0-9a-f]' AND BINARY event.template_revision NOT REGEXP '[^0-9a-f]'";
                $path = DB::getDriverName() === 'sqlite'
                    ? "substr(event.stored_path, 1, length('reservation-documents/' || event.reservation_id || '/')) = ('reservation-documents/' || event.reservation_id || '/') AND substr(event.stored_path, -4) = '.pdf' AND length(event.stored_path) = length('reservation-documents/' || event.reservation_id || '/') + 44 AND substr(event.stored_path, -44, 40) NOT GLOB '*[^A-Za-z0-9]*'"
                    : "BINARY event.stored_path REGEXP CONCAT('^reservation-documents/', event.reservation_id, '/[A-Za-z0-9]{40}[.]pdf$') AND length(event.stored_path) = length(CONCAT('reservation-documents/', event.reservation_id, '/')) + 44";
                $mime = DB::getDriverName() === 'sqlite' ? "event.mime_type = 'application/pdf'" : "BINARY event.mime_type = 'application/pdf'";
                $validity .= " AND ($mime) AND event.file_size > 0 AND event.template_version >= 1"
                    ." AND length(event.content_hash) = 64 AND length(event.template_revision) = 64 AND ($hex) AND ($path)"
                    .' AND length(trim(event.template_filename)) > 0 AND length(trim(event.original_filename)) > 0';

            }
            // CASE explicitly partitions FALSE and NULL metadata into the warning population.
            $partition = "CASE WHEN $validity THEN 1 ELSE 0 END";
            $valid = (clone $base)->whereRaw("($partition) = 1");
            $issues = (clone $base)->whereRaw("($partition) = 0")->count();
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
            $events = (clone $valid)->leftJoin('users as actor', 'actor.id', '=', 'event.'.$actor)
                ->select('event.id', 'event.reservation_id', 'event.'.$time.' as occurred_at', 'resident.name as resident_name',
                    's.name as service_name', 'actor.name as actor_name')
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
