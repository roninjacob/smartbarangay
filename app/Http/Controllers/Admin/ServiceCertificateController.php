<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\CertificatePreview;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ServiceCertificateController extends Controller
{
    public function index(Request $request, Service $service, CertificatePreview $preview): View
    {
        $data = $request->validate(['reservation' => ['nullable', 'integer', Rule::exists('reservations', 'id')
            ->where('service_id', $service->id)->where('status', ReservationStatus::Approved->value)]]);
        $service->load('documentTemplate');
        $reservations = $service->reservations()->with(['user', 'schedule', 'document'])
            ->where('status', ReservationStatus::Approved)
            ->when($data['reservation'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->oldest()->orderBy('id')->paginate(15)->withQueryString();

        return view('admin.services.certificates', [
            'roleLabel' => 'Admin', 'navigation' => config('navigation.admin'), 'dashboardDate' => now('Asia/Manila'),
            'pageTitle' => 'Official Certificate PDF', 'pageHeading' => 'Official Certificate PDF', 'pageSection' => 'Services & Documents',
            'service' => $service, 'reservations' => $reservations, 'selected' => $data['reservation'] ?? null,
            'preview' => $preview->service($service),
        ]);
    }
}
