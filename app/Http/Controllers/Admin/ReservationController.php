<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FilterReservationsRequest;
use App\Http\Requests\Admin\UpdateReservationStatusRequest;
use App\Models\Reservation;
use App\Services\QrTicketIssuer;
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
        $reservations = Reservation::with(['user', 'service', 'schedule'])
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $query) use ($search) {
                    $query->whereHas('user', fn (Builder $user) => $user->where('name', 'like', '%'.$search.'%'))
                        ->orWhereHas('service', fn (Builder $service) => $service->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->latest()->orderByDesc('id')->paginate(10)->appends($filters);

        return view('admin.reservations.index', [...$this->shellData('Reservation Management'), 'reservations' => $reservations, 'filters' => $filters, 'statuses' => ReservationStatus::cases()]);
    }

    public function show(Reservation $reservation): View
    {
        $reservation->load(['user', 'service.serviceRequirements', 'schedule', 'qrTicket', 'attachments.serviceRequirement']);

        return view('admin.reservations.show', [
            ...$this->shellData('Reservation Details'), 'reservation' => $reservation,
            'transitions' => $reservation->status->allowedTransitions(),
            'histories' => $reservation->statusHistories()->with('changedBy')->orderByDesc('changed_at')->orderByDesc('id')->paginate(10),
        ]);
    }

    public function updateStatus(UpdateReservationStatusRequest $request, Reservation $reservation, QrTicketIssuer $tickets): RedirectResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($request, $reservation, $data, $tickets) {
            $locked = Reservation::lockForUpdate()->findOrFail($reservation->id);
            $next = ReservationStatus::from($data['status']);
            if ($locked->status->value !== $data['expected_status']) {
                throw ValidationException::withMessages(['status' => 'This reservation has changed. Review the latest status before updating.']);
            }
            if (! in_array($next, $locked->status->allowedTransitions(), true)) {
                throw ValidationException::withMessages(['status' => 'This status transition is not allowed.']);
            }
            $previous = $locked->status;
            $locked->update(['status' => $next]);
            if ($next === ReservationStatus::Approved) {
                $tickets->issue($locked);
            }
            $locked->statusHistories()->create([
                'from_status' => $previous, 'to_status' => $next, 'changed_by' => $request->user()->id,
                'notes' => $data['notes'] ?? null, 'changed_at' => now(),
            ]);
        });

        return to_route('admin.reservations.show', $reservation)->with('status', 'Reservation status updated. The change has been recorded in history.');
    }

    private function shellData(string $heading): array
    {
        return ['roleLabel' => 'Admin', 'navigation' => config('navigation.admin'), 'dashboardDate' => now('Asia/Manila'),
            'pageTitle' => $heading, 'pageHeading' => $heading, 'pageSection' => 'Reservation Management'];
    }
}
