<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FilterReservationsRequest;
use App\Http\Requests\Admin\UpdateReservationStatusRequest;
use App\Models\CheckinLog;
use App\Models\Reservation;
use App\Services\CertificatePreparationState;
use App\Services\CheckInReservations;
use App\Services\GeneratedReservationDocuments;
use App\Services\QrTicketIssuer;
use App\Services\ReservationActivityTimeline;
use App\Services\ReservationDocumentPreview;
use App\Services\ReservationNotifications;
use App\Services\ReservationPayments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ReservationController extends Controller
{
    public function index(FilterReservationsRequest $request): View
    {
        $filters = $request->validated();
        $search = $filters['search'] ?? '';
        $today = now('Asia/Manila')->startOfDay();
        $start = $today->copy()->utc();
        $end = $today->copy()->addDay()->utc();
        // Operational overview, independent of the list filters. Completion time comes from history.
        $overview = Reservation::query()->selectRaw(
            'COALESCE(SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END), 0) AS needs_review,
             COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS ready_for_pickup,
             COALESCE(SUM(CASE WHEN status = ? AND EXISTS (
                SELECT 1 FROM reservation_status_histories h WHERE h.reservation_id = reservations.id
                AND h.to_status = ? AND h.changed_at >= ? AND h.changed_at < ?
             ) THEN 1 ELSE 0 END), 0) AS completed_today',
            [ReservationStatus::Pending->value, ReservationStatus::UnderReview->value,
                ReservationStatus::ReadyForPickup->value, ReservationStatus::Completed->value,
                ReservationStatus::Completed->value, $start, $end]
        )->first();
        $reservations = Reservation::with(['user', 'service', 'schedule'])
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $query) use ($search) {
                    $query->whereHas('user', fn (Builder $user) => $user->where('name', 'like', '%'.$search.'%'))
                        ->orWhereHas('service', fn (Builder $service) => $service->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->latest()->orderByDesc('id')->paginate(10)->appends($filters);

        return view('admin.reservations.index', [...$this->shellData('Reservation Management'),
            'overview' => $overview, 'reservations' => $reservations, 'filters' => $filters, 'statuses' => ReservationStatus::cases()]);
    }

    public function show(Reservation $reservation, CertificatePreparationState $documents, GeneratedReservationDocuments $generated): View
    {
        $reservation->load(['user', 'service.serviceRequirements', 'service.documentTemplate', 'document.generator', 'schedule', 'qrTicket.usedBy', 'attachments.requirementSnapshot', 'requirementSnapshots', 'documentAnswers', 'payment.recorder']);
        $previewAvailable = ! $reservation->document && app(ReservationDocumentPreview::class)->data($reservation) !== null;
        $snapshot = $reservation->document_template_snapshot;
        $prepared = $documents->isPrepared($reservation);
        $certificateState = $generated->availability($reservation);
        if ($certificateState['available'] && ! $prepared) {
            $certificateState = ['available' => false, 'can_print' => false, 'message' => GeneratedReservationDocuments::INVALID];
        }

        return view('admin.reservations.show', [
            'checkin' => $reservation->checkinLogs()->where('verification_result', CheckinLog::ARRIVAL)->with('verifier')->first(),
            'checkinVerified' => app(CheckInReservations::class)->hasEvidence(request()->session()->get(CheckInReservations::SESSION_KEY), request()->user(), $reservation),
            ...$this->shellData('Reservation Details'), 'reservation' => $reservation,
            'paymentStatus' => app(ReservationPayments::class)->status($reservation),
            'completionBlockReason' => $reservation->status === ReservationStatus::ReadyForPickup
                ? app(ReservationPayments::class)->completionBlockReason($reservation) : null,
            'transitions' => $reservation->status->allowedTransitions(),
            'hasPreparedCertificate' => $prepared,
            'certificateState' => $certificateState,
            'documentPreviewAvailable' => $previewAvailable,
            'canGenerateCertificate' => $reservation->status->canPrepareDocument() && $previewAvailable
                && is_int($snapshot['version'] ?? null) && $snapshot['version'] > 0,
            'activities' => app(ReservationActivityTimeline::class)->forReservation($reservation->id),
        ]);
    }

    public function updateStatus(UpdateReservationStatusRequest $request, Reservation $reservation, QrTicketIssuer $tickets, CertificatePreparationState $documents): RedirectResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($request, $reservation, $data, $tickets, $documents) {
            $locked = Reservation::lockForUpdate()->findOrFail($reservation->id);
            $next = ReservationStatus::from($data['status']);
            if ($locked->status->value !== $data['expected_status']) {
                throw ValidationException::withMessages(['status' => 'This reservation has changed. Review the latest status before updating.']);
            }
            if (! in_array($next, $locked->status->allowedTransitions(), true)) {
                throw ValidationException::withMessages(['status' => 'This status transition is not allowed.']);
            }
            if ($next === ReservationStatus::Completed && ($reason = app(ReservationPayments::class)->completionBlockReason($locked))) {
                throw ValidationException::withMessages(['status' => $reason]);
            }
            $previous = $locked->status;
            if ($next === ReservationStatus::ReadyForPickup && ! $documents->isPrepared($locked) && blank($data['notes'] ?? null)) {
                throw ValidationException::withMessages(['notes' => 'No prepared certificate/document was found for this request. Enter a reason to continue, such as "Existing certificate was prepared manually."']);
            }
            $changedAt = now();
            $locked->update(['status' => $next]);
            if ($next === ReservationStatus::Approved) {
                $tickets->issue($locked);
            }
            if ($next === ReservationStatus::Completed) {
                $ticket = $locked->qrTicket()->lockForUpdate()->first();
                if ($ticket && ! $ticket->isUsed()) {
                    // Server-controlled audit fields. The reservation lock also serializes completion replays.
                    $ticket->forceFill(['used_at' => $changedAt, 'used_by' => $request->user()->id])->save();
                }
            }
            $history = $locked->statusHistories()->create([
                'from_status' => $previous, 'to_status' => $next, 'changed_by' => $request->user()->id,
                'notes' => $data['notes'] ?? null, 'changed_at' => $changedAt,
            ]);
            app(ReservationNotifications::class)->record($next->value, $locked, $history);
        });

        return to_route('admin.reservations.show', $reservation)->with('status', 'Reservation status updated. The change has been recorded in history.');
    }

    private function shellData(string $heading): array
    {
        return ['roleLabel' => 'Admin', 'navigation' => config('navigation.admin'), 'dashboardDate' => now('Asia/Manila'),
            'pageTitle' => $heading, 'pageHeading' => $heading, 'pageSection' => 'Reservation Management'];
    }
}
