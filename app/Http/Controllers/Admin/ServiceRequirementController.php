<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveServiceRequirementRequest;
use App\Models\Service;
use App\Models\ServiceRequirement;
use App\Services\ConfigurationAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        DB::transaction(function () use ($request, $service) {
            Service::lockForUpdate()->findOrFail($service->id);
            $requirement = $service->serviceRequirements()->create($request->safe()->only(['name', 'description', 'is_required']));
            $audit = app(ConfigurationAudit::class);
            $audit->record($request->user(), $requirement, 'created', [], $audit->values($requirement));
        });

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
        DB::transaction(function () use ($request, $service, $serviceRequirement) {
            Service::lockForUpdate()->findOrFail($service->id);
            $locked = $service->serviceRequirements()->lockForUpdate()->findOrFail($serviceRequirement->id);
            $audit = app(ConfigurationAudit::class);
            $old = $audit->values($locked);
            $locked->update($request->safe()->only(['name', 'description', 'is_required']));
            $audit->record($request->user(), $locked, 'updated', $old, $audit->values($locked));
        });

        return to_route('admin.services.requirements.index', $service)->with('status', 'Requirement updated successfully.');
    }

    public function destroy(Request $request, Service $service, ServiceRequirement $serviceRequirement): RedirectResponse
    {
        $deleted = DB::transaction(function () use ($request, $service, $serviceRequirement): bool {
            Service::lockForUpdate()->findOrFail($service->id);
            $requirement = $service->serviceRequirements()->whereKey($serviceRequirement->id)->lockForUpdate()->firstOrFail();
            if ($requirement->attachments()->exists()) {
                return false;
            }
            $audit = app(ConfigurationAudit::class);
            $old = $audit->values($requirement);
            $requirement->delete();
            $audit->record($request->user(), $requirement, 'deleted', $old, []);

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
