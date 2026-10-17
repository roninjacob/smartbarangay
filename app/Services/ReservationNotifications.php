<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\ReservationPayment;
use App\Models\ReservationStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Explicit communication policy. Never called from model observers or read workflows. */
class ReservationNotifications
{
    public const TYPES = [
        'submitted' => ['request_submitted', 'confirmation', 'Request Submitted'],
        'under_review' => ['request_under_review', 'status_update', 'Request Under Review'],
        'approved' => ['request_approved', 'success', 'Request Approved'],
        'ready_for_pickup' => ['ready_for_pickup', 'action_required', 'Ready for Pickup'],
        'payment' => ['payment_recorded', 'payment', 'Payment Recorded'],
        'completed' => ['request_completed', 'completion', 'Request Completed'],
        'rejected' => ['request_rejected', 'important_status', 'Request Rejected'],
        'cancelled' => ['request_cancelled', 'confirmation', 'Request Cancelled'],
    ];

    public static function typesFor(User $user): array
    {
        return $user->role === UserRole::Admin
            ? ['admin.new_reservation', 'admin.resident_cancelled']
            : array_map(fn ($definition) => 'resident.'.$definition[0], array_values(self::TYPES));
    }

    public function record(string $event, Reservation $reservation, ?Model $source = null): void
    {
        if (! isset(self::TYPES[$event])) {
            return; // Unknown events are deliberately silent.
        }
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Notifications must share the authoritative business transaction.');
        }
        $validSource = match ($event) {
            'submitted' => $reservation->exists && $reservation->status->value === 'pending',
            'payment' => $source instanceof ReservationPayment && $source->exists && $source->reservation_id === $reservation->id,
            default => $source instanceof ReservationStatusHistory && $source->exists
                && $source->reservation_id === $reservation->id && $source->to_status->value === $event,
        };
        if (! $validSource) {
            throw new LogicException('Notification source must match the persisted business event.');
        }
        $reservation->loadMissing(['user', 'service']);
        $service = $reservation->service->name;
        $resident = $reservation->user->name;
        $request = $service.' request #'.$reservation->id;
        $message = match ($event) {
            'submitted' => 'Your '.$request.' was submitted successfully. You can track its progress from My Reservations.',
            'under_review' => 'Your '.$request.' is now being reviewed.',
            'approved' => 'Your '.$request.' has been approved. Open your request to view the latest details.',
            'ready_for_pickup' => 'Your '.$request.' is ready for pickup at the Barangay office. Open your request to review the latest details.',
            'payment' => 'Your payment of ₱'.$source->amount.' for request #'.$reservation->id.' has been recorded.',
            'completed' => 'Your '.$request.' has been completed.',
            'rejected' => 'Your '.$request.' was not approved. Open your request for the available details.',
            'cancelled' => 'Your '.$request.' was cancelled successfully.',
        };
        $key = match ($event) {
            'submitted' => 'submission:'.$reservation->id,
            'payment' => 'payment:'.$source->id,
            default => 'status:'.$source->id,
        };
        [$type, $category, $title] = self::TYPES[$event];
        $context = ['reservation_id' => $reservation->id, 'service_label' => $service];
        $this->persist($reservation->user, $key, 'resident.'.$type, $category, $title, $message, $context);
        if (in_array($event, ['submitted', 'cancelled'], true)) {
            // Chunk eligible recipients; never load all notification histories or all accounts.
            User::where('role', UserRole::Admin->value)->where('is_active', true)->whereNotNull('email_verified_at')
                ->chunkById(100, function ($admins) use ($event, $key, $context, $request, $resident) {
                    foreach ($admins as $admin) {
                        $this->persist($admin, $key, $event === 'submitted' ? 'admin.new_reservation' : 'admin.resident_cancelled',
                            $event === 'submitted' ? 'action_required' : 'operational_update',
                            $event === 'submitted' ? 'New Reservation' : 'Reservation Cancelled',
                            $event === 'submitted' ? 'A new '.$request.' was submitted by '.$resident.' and is waiting for review.'
                                : $resident.' cancelled '.$request.'.', [...$context, 'resident_label' => $resident]);
                    }
                });
        }
    }

    private function persist(User $recipient, string $key, string $type, string $category, string $title, string $message, array $context): void
    {
        // Laravel's native DatabaseNotification relation + unique recipient/event index.
        // firstOrCreate preserves original text and read state on an event replay.
        $recipient->notifications()->firstOrCreate(['event_key' => $key], [
            'id' => (string) Str::uuid(), 'type' => $type,
            'data' => [...$context, 'category' => $category, 'title' => $title, 'message' => $message],
            'read_at' => null,
        ]);
    }
}
