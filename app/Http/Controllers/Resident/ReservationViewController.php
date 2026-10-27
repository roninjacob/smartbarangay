<?php

namespace App\Http\Controllers\Resident;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Services\ReservationPayments;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReservationViewController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(ReservationStatus::class)],
            'view' => ['nullable', 'in:all,active,history'],
        ]);
        $search = trim($filters['search'] ?? '');
        $query = $request->user()->reservations()->with(['service', 'schedule']);
        if (($filters['view'] ?? 'all') !== 'all') {
            $statuses = array_map(fn ($status) => $status->value, ReservationStatus::occupyingStatuses());
            ($filters['view'] === 'active') ? $query->whereIn('status', $statuses) : $query->whereNotIn('status', $statuses);
        }
        $query->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status));
        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->whereHas('service', fn ($service) => $service->where('name', 'like', '%'.$search.'%'));
                $id = ltrim($search, '#');
                if (ctype_digit($id) && strlen($id) <= 18) {
                    $query->orWhere('reservations.id', $id);
                }
            });
        }

        return view('resident.reservations.index', [
            ...$this->shellData('My Reservations'), 'filters' => $filters, 'statuses' => ReservationStatus::cases(),
            'reservations' => $query->latest()->orderByDesc('id')->paginate(10)->withQueryString(),
        ]);
    }

    public function show(Request $request, string $reservation): View
    {
        // Scope the lookup before loading related information. Foreign and missing IDs both return 404.
        $ownedReservation = $request->user()->reservations()->whereKey($reservation)->firstOrFail();
        $ownedReservation->load(['service.serviceRequirements', 'schedule', 'attachments.requirementSnapshot', 'requirementSnapshots', 'documentAnswers', 'document.generator', 'payment', 'qrTicket']);

        return view('resident.reservations.show', [
            ...$this->shellData('Reservation Details'), 'reservation' => $ownedReservation,
            'residentMessage' => $ownedReservation->status === ReservationStatus::Rejected
                ? $ownedReservation->statusHistories()->where('to_status', ReservationStatus::Rejected)->orderByDesc('changed_at')->orderByDesc('id')->value('resident_message') : null,
            'paymentStatus' => app(ReservationPayments::class)->status($ownedReservation),
        ]);
    }

    private function shellData(string $heading): array
    {
        return [
            'roleLabel' => 'Resident', 'navigation' => config('navigation.resident'),
            'dashboardDate' => now('Asia/Manila'), 'pageTitle' => $heading,
            'pageHeading' => $heading, 'pageSection' => 'My Reservations',
        ];
    }
}
