<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveServiceRequirementRequest;
use App\Models\Service;
use App\Models\ServiceRequirement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ServiceRequirementController extends Controller
{
    public function index(Service $service): View
    {
        return view('admin.requirements.index', [
            ...$this->shellData('Service Requirements'),
            'service' => $service,
            'requirements' => $service->serviceRequirements()->withExists('attachments')
                ->orderBy('name')->orderBy('id')->paginate(15),
        ]);
    }

    public function create(Service $service): View
    {
        return view('admin.requirements.form', [
            ...$this->shellData('Add Requirement'),
            'service' => $service,
            'requirement' => new ServiceRequirement(['is_required' => true]),
        ]);
    }

    public function store(SaveServiceRequirementRequest $request, Service $service): RedirectResponse
    {
        $service->serviceRequirements()->create($request->safe()->only(['name', 'description', 'is_required']));

        return to_route('admin.services.requirements.index', $service)->with('status', 'Requirement added successfully.');
    }

    public function edit(Service $service, ServiceRequirement $serviceRequirement): View
    {
        return view('admin.requirements.form', [
            ...$this->shellData('Edit Requirement'), 'service' => $service, 'requirement' => $serviceRequirement,
        ]);
    }

    public function update(SaveServiceRequirementRequest $request, Service $service, ServiceRequirement $serviceRequirement): RedirectResponse
    {
        $serviceRequirement->update($request->safe()->only(['name', 'description', 'is_required']));

        return to_route('admin.services.requirements.index', $service)->with('status', 'Requirement updated successfully.');
    }

    public function destroy(Service $service, ServiceRequirement $serviceRequirement): RedirectResponse
    {
        $deleted = DB::transaction(function () use ($service, $serviceRequirement): bool {
            $requirement = $service->serviceRequirements()->whereKey($serviceRequirement->id)->lockForUpdate()->firstOrFail();
            if ($requirement->attachments()->exists()) {
                return false;
            }
            $requirement->delete();

            return true;
        });

        $redirect = to_route('admin.services.requirements.index', $service);

        return $deleted
            ? $redirect->with('status', 'Requirement deleted. The service and other requirements are unchanged.')
            : $redirect->withErrors(['requirement' => 'This requirement cannot be deleted because it is linked to an existing attachment.']);
    }

    private function shellData(string $heading): array
    {
        return [
            'roleLabel' => 'Admin', 'navigation' => config('navigation.admin'),
            'dashboardDate' => now('Asia/Manila'), 'pageTitle' => $heading,
            'pageHeading' => $heading, 'pageSection' => 'Services & Documents',
        ];
    }
}
