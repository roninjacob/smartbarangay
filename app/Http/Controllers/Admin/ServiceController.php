<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveServiceRequest;
use App\Http\Requests\Admin\UpdateServiceStatusRequest;
use App\Models\Service;
use App\Services\ServiceDocumentTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class ServiceController extends Controller
{
    public function index(): View
    {
        return view('admin.services.index', [
            ...$this->shellData('Services & Documents'),
            'services' => Service::with('documentTemplate')->orderBy('name')->orderBy('id')->paginate(15),
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
                $service = Service::create([...$request->safe()->only(['name', 'description']), 'is_active' => true]);
                if ($request->hasFile('official_template')) {
                    $templates->replace($service, $request->file('official_template'), $request->user()->id, $newPaths);
                }
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
                $locked = Service::lockForUpdate()->findOrFail($service->id);
                $locked->update($request->safe()->only(['name', 'description']));
                if ($request->hasFile('official_template')) {
                    $previous = $templates->replace($locked, $request->file('official_template'), $request->user()->id, $newPaths);
                }
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
        $service->update(['is_active' => $request->boolean('is_active')]);

        return to_route('admin.services.index')->with('status', $service->is_active ? 'Service activated.' : 'Service deactivated. Existing records are preserved.');
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
