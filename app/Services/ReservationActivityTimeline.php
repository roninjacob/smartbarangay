<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Support\ServiceFee;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read model only: no business records or materialized activity rows are written. */
class ReservationActivityTimeline
{
    public const TYPES = [
        'submitted' => 'Request Submitted', 'status_changed' => 'Status Changed',
        'qr_issued' => 'QR Issued', 'document_finalized' => 'Certificate Finalized',
        'checked_in' => 'Checked In', 'payment_recorded' => 'Payment Recorded',
    ];

    private const SOURCES = [
        'submitted' => 'reservations', 'status_changed' => 'reservation_status_histories',
        'qr_issued' => 'qr_tickets', 'document_finalized' => 'reservation_documents',
        'checked_in' => 'checkin_logs', 'payment_recorded' => 'reservation_payments',
    ];

    public function forReservation(int $reservationId): Collection
    {
        return $this->ordered($this->query($reservationId), 'asc')->get()->map($this->normalize(...));
    }

    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = $this->query(null, $filters['type'] ?? null);
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $query->where(function (Builder $query) use ($search) {
                $query->where('resident.name', 'like', '%'.$search.'%')
                    ->orWhere('service.name', 'like', '%'.$search.'%')
                    ->orWhereRaw($this->historicalResidentName().' LIKE ?', ['%'.$search.'%']);
                $number = preg_replace('/^(?:request\s*)?#\s*|^request\s+/i', '', $search);
                if (ctype_digit($number)) {
                    $query->orWhere('r.id', $number);
                }
            });
        }
        $page = $this->ordered($query, 'desc')->paginate(15)->appends($filters);
        $page->setCollection($page->getCollection()->map($this->normalize(...)));

        return $page;
    }

    private function query(?int $reservationId, ?string $type = null): Builder
    {
        if ($type && ! array_key_exists($type, self::TYPES)) {
            throw new \InvalidArgumentException('Unknown reservation activity type.');
        }
        // Every branch has the same explicit, safe projection; credentials and file paths never enter the read model.
        $completionMatches = "h.to_status = 'completed' AND q.used_at = h.changed_at AND q.used_by = h.changed_by";
        $branches = [
            'submitted' => DB::table('reservations as s')->selectRaw("s.id AS source_id, s.id AS reservation_id, s.created_at AS occurred_at, s.user_id AS actor_id, 'submitted' AS event_type, 10 AS priority, NULL AS from_status, NULL AS to_status, NULL AS note, NULL AS amount, '' AS qr_usage"),
            'status_changed' => DB::table('reservation_status_histories as h')->leftJoin('qr_tickets as q', 'q.reservation_id', '=', 'h.reservation_id')
                ->selectRaw("h.id AS source_id, h.reservation_id, h.changed_at AS occurred_at, h.changed_by AS actor_id, 'status_changed' AS event_type, CASE h.to_status WHEN 'pending' THEN 15 WHEN 'under_review' THEN 20 WHEN 'approved' THEN 30 WHEN 'ready_for_pickup' THEN 60 WHEN 'completed' THEN 90 ELSE 25 END AS priority, h.from_status, h.to_status, h.notes AS note, NULL AS amount, CASE WHEN h.to_status = 'completed' AND q.used_at IS NOT NULL THEN CASE WHEN $completionMatches THEN 'matched' ELSE 'unmatched' END ELSE '' END AS qr_usage"),
            'qr_issued' => DB::table('qr_tickets as q')->selectRaw("q.id AS source_id, q.reservation_id, q.generated_at AS occurred_at, NULL AS actor_id, 'qr_issued' AS event_type, 40 AS priority, NULL AS from_status, NULL AS to_status, NULL AS note, NULL AS amount, CASE WHEN q.used_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM reservation_status_histories h WHERE h.reservation_id = q.reservation_id AND $completionMatches) THEN 'unmatched' ELSE '' END AS qr_usage"),
            'document_finalized' => DB::table('reservation_documents as d')->selectRaw("d.id AS source_id, d.reservation_id, d.generated_at AS occurred_at, d.generated_by AS actor_id, 'document_finalized' AS event_type, 50 AS priority, NULL AS from_status, NULL AS to_status, NULL AS note, NULL AS amount, '' AS qr_usage"),
            'checked_in' => DB::table('checkin_logs as c')->where('c.verification_result', 'checked_in')->whereNotNull('c.reservation_id')
                ->selectRaw("c.id AS source_id, c.reservation_id, c.verified_at AS occurred_at, c.verified_by AS actor_id, 'checked_in' AS event_type, 70 AS priority, NULL AS from_status, NULL AS to_status, NULL AS note, NULL AS amount, '' AS qr_usage"),
            'payment_recorded' => DB::table('reservation_payments as p')->selectRaw("p.id AS source_id, p.reservation_id, p.paid_at AS occurred_at, p.recorded_by AS actor_id, 'payment_recorded' AS event_type, 80 AS priority, NULL AS from_status, NULL AS to_status, NULL AS note, p.amount, '' AS qr_usage"),
        ];
        $union = null;
        foreach ($branches as $eventType => $branch) {
            if ($type && $eventType !== $type) {
                continue;
            }
            if ($reservationId !== null) {
                $alias = ['submitted' => 's.id', 'status_changed' => 'h.reservation_id', 'qr_issued' => 'q.reservation_id',
                    'document_finalized' => 'd.reservation_id', 'checked_in' => 'c.reservation_id', 'payment_recorded' => 'p.reservation_id'][$eventType];
                $branch->where($alias, $reservationId);
            }
            $union = $union ? $union->unionAll($branch) : $branch;
        }

        return DB::query()->fromSub($union, 'activity')
            ->join('reservations as r', 'r.id', '=', 'activity.reservation_id')
            ->leftJoin('users as resident', 'resident.id', '=', 'r.user_id')
            ->leftJoin('services as service', 'service.id', '=', 'r.service_id')
            ->leftJoin('users as actor', 'actor.id', '=', 'activity.actor_id')
            ->whereNotNull('activity.occurred_at')
            ->select('activity.*', 'service.name as service_name', 'actor.name as actor_name')
            ->selectRaw('COALESCE('.$this->historicalResidentName().', resident.name) AS resident_name');
    }

    private function historicalResidentName(): string
    {
        // Legacy rows may have no snapshot. Guard malformed JSON before extracting the historical name.
        $value = "JSON_EXTRACT(CASE WHEN JSON_VALID(r.resident_snapshot) THEN r.resident_snapshot ELSE '{}' END, '$.resident_name')";

        return DB::getDriverName() === 'sqlite' ? $value : 'JSON_UNQUOTE('.$value.')';
    }

    private function ordered(Builder $query, string $direction): Builder
    {
        return $query->orderBy('activity.occurred_at', $direction)->orderBy('activity.priority', $direction)
            ->orderBy('activity.source_id', $direction)->orderBy('activity.event_type', $direction);
    }

    private function normalize(object $row): array
    {
        $type = $row->event_type;
        $title = self::TYPES[$type];
        $description = match ($type) {
            'submitted' => 'Resident submitted the request.',
            'qr_issued' => 'QR credential issued by the system. Stored issuance time; older records may have timestamp uncertainty.',
            'document_finalized' => 'Personalized certificate saved.',
            'checked_in' => 'Physical arrival recorded. Payment and completion remain separate.',
            'payment_recorded' => 'On-site payment recorded: '.ServiceFee::format((string) $row->amount).'.',
            default => '',
        };
        if ($type === 'status_changed') {
            $to = ReservationStatus::tryFrom($row->to_status ?? '')?->label() ?? 'Unknown status';
            $from = ReservationStatus::tryFrom($row->from_status ?? '')?->label();
            $title = $to;
            $description = $from ? $from.' → '.$to : 'Initial status: '.$to;
        }
        try {
            $time = CarbonImmutable::parse($row->occurred_at, 'UTC')->setTimezone('Asia/Manila');
        } catch (InvalidFormatException) {
            $time = null;
        }

        return ['type' => $type, 'title' => $title, 'timestamp' => $time,
            'actor' => $type === 'qr_issued' ? 'System' : ($type === 'submitted' ? ($row->resident_name ?? 'Former account') : ($row->actor_name ?? 'Former account')),
            'description' => $description, 'note' => $row->note,
            'qr_used' => $row->qr_usage === 'matched',
            'warning' => $row->qr_usage === 'unmatched' ? 'QR usage does not match a recorded completion timestamp and actor. Review original records.' : null,
            'source' => ['table' => self::SOURCES[$type], 'id' => (int) $row->source_id],
            'reservation_id' => (int) $row->reservation_id, 'resident' => $row->resident_name ?? 'Former account',
            'service' => $row->service_name ?? 'Unavailable service'];
    }
}
