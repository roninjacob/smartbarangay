<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveServiceRequest;
use App\Http\Requests\Admin\UpdateServiceStatusRequest;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ServiceController extends Controller
{
    public function index(): View
    {
        return view('admin.services.index', [
            ...$this->shellData('Services & Documents'),
            'services' => Service::orderBy('name')->orderBy('id')->paginate(15),
        ]);
    }

    public function create(): View
    {
        return view('admin.services.form', [...$this->shellData('Create Service'), 'service' => new Service]);
    }

    public function store(SaveServiceRequest $request): RedirectResponse
    {
        Service::create([...$request->safe()->only(['name', 'description']), 'is_active' => true]);

        return to_route('admin.services.index')->with('status', 'Service created successfully.');
    }

    public function edit(Service $service): View
    {
        return view('admin.services.form', [...$this->shellData('Edit Service'), 'service' => $service]);
    }

    public function update(SaveServiceRequest $request, Service $service): RedirectResponse
    {
        $service->update($request->safe()->only(['name', 'description']));

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
