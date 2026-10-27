<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveServiceRequest;
use App\Http\Requests\Admin\UpdateServiceStatusRequest;
use App\Models\Service;
use App\Services\AccountEligibility;
use App\Services\ConfigurationAudit;
use App\Services\ReservationDocumentInformation;
use App\Services\ServiceDocumentTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class ServiceController extends Controller
{
    public function index(): View
    {
        $services = Service::with('documentTemplate')->orderBy('name')->orderBy('id')->paginate(15);
        $readiness = [];
        foreach ($services as $service) {
            try {
                $template = app(ReservationDocumentInformation::class)->configuration($service);
                $readiness[$service->id] = $service->fee_amount === null ? 'Fee setup required'
                    : ($template ? 'Dynamic printing ready' : 'Manual processing');
            } catch (ValidationException) {
                $readiness[$service->id] = 'Setup needs attention';
            }
        }

        return view('admin.services.index', [
            ...$this->shellData('Services & Documents'),
            'services' => $services, 'readiness' => $readiness,
        ]);
    }

    public function create(): View
    {
        return view('admin.services.form', [...$this->shellData('Create Service'), 'service' => new Service]);
    }

    public function store(SaveServiceRequest $request, ServiceDocumentTemplates $templates): RedirectResponse
    {
        $newPaths = [];
        try {
            DB::transaction(function () use ($request, $templates, &$newPaths) {
                app(AccountEligibility::class)->lock($request->user(), UserRole::Admin);
                $service = Service::create([...$request->safe()->only(['name', 'description', 'fee_amount']), 'is_active' => true]);
                if ($request->hasFile('official_template')) {
                    $templates->replace($service, $request->file('official_template'), $request->user()->id, $newPaths);
                }
                $audit = app(ConfigurationAudit::class);
                $audit->record($request->user(), $service, 'created', [], $audit->values($service));
            });
        } catch (Throwable $exception) {
            $templates->cleanup($newPaths);
            throw $exception;
        }

        return to_route('admin.services.index')->with('status', 'Service created successfully.');
    }

    public function edit(Service $service): View
    {
        return view('admin.services.form', [...$this->shellData('Edit Service'), 'service' => $service->load('documentTemplate')]);
    }

    public function update(SaveServiceRequest $request, Service $service, ServiceDocumentTemplates $templates): RedirectResponse
    {
        $newPaths = [];
        $previous = null;
        try {
            DB::transaction(function () use ($request, $service, $templates, &$newPaths, &$previous) {
                app(AccountEligibility::class)->lock($request->user(), UserRole::Admin);
                $locked = Service::lockForUpdate()->findOrFail($service->id);
                $audit = app(ConfigurationAudit::class);
                $audit->requireRevision($locked, $request->input('edit_revision'));
                $old = $audit->values($locked);
                $locked->update($request->safe()->only(['name', 'description', 'fee_amount']));
                if ($request->hasFile('official_template')) {
                    $previous = $templates->replace($locked, $request->file('official_template'), $request->user()->id, $newPaths);
                }
                $audit->record($request->user(), $locked, 'updated', $old, $audit->values($locked));
            });
        } catch (Throwable $exception) {
            $templates->cleanup($newPaths);
            throw $exception;
        }
        if ($previous !== null) {
            $templates->cleanup([$previous]);
        }

        return to_route('admin.services.index')->with('status', 'Service updated successfully.');
    }

    public function updateStatus(UpdateServiceStatusRequest $request, Service $service): RedirectResponse
    {
        DB::transaction(function () use ($request, $service) {
            app(AccountEligibility::class)->lock($request->user(), UserRole::Admin);
            $locked = Service::lockForUpdate()->findOrFail($service->id);
            $audit = app(ConfigurationAudit::class);
            $old = $audit->values($locked);
            $locked->update(['is_active' => $request->boolean('is_active')]);
            $audit->record($request->user(), $locked, 'updated', $old, $audit->values($locked));
        });

        return to_route('admin.services.index')->with('status', $request->boolean('is_active') ? 'Service activated.' : 'Service deactivated. Existing records are preserved.');
    }

    private function shellData(string $heading): array
    {
        return [
            'roleLabel' => 'Admin',
            'navigation' => config('navigation.admin'),
            'dashboardDate' => now('Asia/Manila'),
            'pageTitle' => $heading,
            'pageHeading' => $heading,
            'pageSection' => 'Services & Documents',
        ];
    }
}
