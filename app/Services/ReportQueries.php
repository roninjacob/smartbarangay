<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Models\CheckinLog;
use App\Models\Reservation;
use App\Models\ReservationPayment;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Shared populations and safe detail projections for report pages and CSV exports. */
class ReportQueries
{
    public function build(string $kind, array $period, array $ids): array
    {
        if ($kind === 'reservations') {
            $population = Reservation::where('reservations.created_at', '>=', $period['from'])->where('reservations.created_at', '<', $period['until']);
            $base = (clone $population)->whereNotIn('reservations.id', $ids);

            return compact('population', 'base') + ['valid' => clone $base, 'invalid' => null,
                'time' => 'reservations.created_at', 'id' => 'reservations.id', 'reservation_key' => 'reservations.id'];
        }
        if ($kind === 'collections') {
            $population = ReservationPayment::where('reservation_payments.paid_at', '>=', $period['from'])->where('reservation_payments.paid_at', '<', $period['until']);
            $base = (clone $population)->whereNotIn('reservation_payments.reservation_id', $ids);
            $valid = (clone $base)->consistent();
            $invalid = (clone $base)->whereNotIn('reservation_payments.id', ReservationPayment::consistent()->select('id'));

            return compact('population', 'base', 'valid', 'invalid') + ['time' => 'reservation_payments.paid_at', 'id' => 'reservation_payments.id', 'reservation_key' => 'reservation_payments.reservation_id'];
        }
        if (! in_array($kind, ['checkins', 'documents'], true)) {
            throw new \InvalidArgumentException('Unknown report kind.');
        }
        $documents = $kind === 'documents';
        $table = $documents ? 'reservation_documents' : 'checkin_logs';
        $time = $documents ? 'generated_at' : 'verified_at';
        $population = DB::table($table.' as event')->where('event.'.$time, '>=', $period['from'])
            ->where('event.'.$time, '<', $period['until']);
        if (! $documents) {
            $population->where('event.verification_result', CheckinLog::ARRIVAL);
        }
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
        $invalid = (clone $base)->whereRaw("($partition) = 0");

        return compact('population', 'base', 'valid', 'invalid') + ['time' => 'event.'.$time, 'id' => 'event.id', 'reservation_key' => 'event.reservation_id'];
    }

    public function reservationCounts(Builder $base): array
    {
        $grouped = (clone $base)->select('status')->selectRaw('COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $counts = [];
        foreach (ReservationStatus::cases() as $status) {
            $counts[$status->value] = (int) ($grouped[$status->value] ?? 0);
        }
        if (count(array_diff(array_keys($grouped->all()), array_keys($counts))) > 0) {
            throw new RuntimeException(ReportingExclusions::UNAVAILABLE);
        }

        return $counts;
    }

    public function details(string $kind, Builder|QueryBuilder $query): Builder|QueryBuilder
    {
        if ($kind === 'reservations') {
            return (clone $query)->select('reservations.id', 'reservations.user_id', 'reservations.service_id', 'reservations.created_at', 'reservations.status')->with(['user:id,name', 'service:id,name']);
        }
        if ($kind === 'collections') {
            return (clone $query)->select('reservation_payments.id', 'reservation_payments.reservation_id', 'reservation_payments.amount', 'reservation_payments.paid_at', 'reservation_payments.recorded_by')
                ->with(['reservation:id,user_id,service_id', 'reservation.user:id,name', 'reservation.service:id,name', 'recorder:id,name']);
        }
        [$time, $actor] = match ($kind) {
            'checkins' => ['verified_at', 'verified_by'], 'documents' => ['generated_at', 'generated_by'],
            default => throw new \InvalidArgumentException('Unknown report kind.'),
        };

        return (clone $query)->leftJoin('users as actor', 'actor.id', '=', 'event.'.$actor)
            ->select('event.id', 'event.reservation_id', 'event.'.$time.' as occurred_at', 'resident.name as resident_name', 's.name as service_name', 'actor.name as actor_name');
    }

    public function sumExpression(): string
    {
        return DB::getDriverName() === 'sqlite'
            ? 'COALESCE(SUM(CAST(ROUND(reservation_payments.amount * 100) AS INTEGER)), 0)'
            : 'COALESCE(SUM(reservation_payments.amount), 0)';
    }

    public function amount(mixed $value): string
    {
        $amount = BigDecimal::of((string) $value);

        return (string) (DB::getDriverName() === 'sqlite' ? $amount->dividedBy(100, 2) : $amount->toScale(2));
    }
}
