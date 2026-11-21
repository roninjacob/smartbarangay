@extends('layouts.authenticated')
@section('dashboard')
<x-page-intro subtitle="Manage service fees, requirements and official documents.">
    <x-slot:actions><a class="btn btn-primary" href="{{ route('admin.services.create') }}"><x-app-icon name="plus"/> Create Service</a></x-slot:actions>
</x-page-intro>

<section class="rv-overview" aria-label="Services on this page">
    <x-dashboard-stat label="Active" :value="$services->getCollection()->where('is_active', true)->count()" icon="check" tone="teal" note="On this page"/>
    <x-dashboard-stat label="Inactive" :value="$services->getCollection()->where('is_active', false)->count()" icon="services" tone="indigo" note="On this page"/>
    <x-dashboard-stat label="Fee setup needed" :value="$services->getCollection()->whereNull('fee_amount')->count()" icon="cash" tone="gold" note="On this page"/>
</section>

<section class="dashboard-panel rv-worklist admin-catalog" aria-labelledby="catalog-heading">
    <div class="dashboard-panel-heading">
        <h2 id="catalog-heading"><span class="panel-icon"><x-app-icon name="services"/></span>Service catalog</h2>
        <span class="panel-count">{{ number_format($services->total()) }} {{ \Illuminate\Support\Str::plural('service', $services->total()) }}</span>
    </div>
    @if($errors->any())<div class="rv-toolbar"><div class="alert alert-danger mb-0" role="alert">{{ $errors->first() }}</div></div>@endif
    @if($services->isEmpty())
        <div class="service-empty"><span class="service-empty-icon"><x-app-icon name="services"/></span><h3>{{ $services->total() ? 'No services on this page' : 'No services yet' }}</h3>
            @if($services->total())<a href="{{ route('admin.services.index') }}">Back to the first page</a>@endif
        </div>
    @else
        <ul class="admin-service-grid list-unstyled">
            @foreach($services as $service)
                <li class="admin-service-card">
                    <header>
                        <span class="panel-icon is-indigo" aria-hidden="true"><x-app-icon name="document"/></span>
                        <h3>{{ $service->name }}</h3>
                        <span @class(['rv-badge', 'is-green' => $service->is_active, 'is-muted' => ! $service->is_active])>{{ $service->is_active ? 'Active' : 'Inactive' }}</span>
                    </header>
                    @if($service->description)<p class="admin-service-description">{{ $service->description }}</p>@endif
                    <dl class="admin-service-facts">
                        <div><dt>Service fee</dt><dd>{{ \App\Support\ServiceFee::format($service->fee_amount, 'Fee setup required') }}</dd></div>
                        <div><dt>Requirements</dt><dd>{{ number_format($service->service_requirements_count) }}</dd></div>
                        <div><dt>Official File</dt><dd>{{ $service->documentTemplate?->format() ?? 'Not uploaded' }}@if($service->documentTemplate)<small>{{ $service->documentTemplate->original_filename }}</small>@endif</dd></div>
                        <div><dt>Document workflow</dt><dd>{{ $readiness[$service->id] }}</dd></div>
                    </dl>
                    <div class="admin-row-actions">
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.services.edit', $service) }}" aria-label="Edit {{ $service->name }}">Edit</a>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" type="button" aria-expanded="false" aria-label="More actions for {{ $service->name }}">More</button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="{{ route('admin.services.requirements.index', $service) }}">Requirements</a></li>
                                <li><a class="dropdown-item" href="{{ route('admin.services.certificates.index', $service) }}">Preview Blank Template</a></li>
                                @if($service->documentTemplate?->format() === 'PDF')
                                    <li><a class="dropdown-item" href="{{ route('admin.services.template.print', $service) }}">Print Blank Template</a></li>
                                    <li><a class="dropdown-item" href="{{ route('admin.services.template.download', $service) }}">Download Blank PDF</a></li>
                                @endif
                                <li><a class="dropdown-item" href="{{ route('admin.services.document-configuration.index', $service) }}">Configure document</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><form method="POST" action="{{ route('admin.services.status', $service) }}" data-service-submit>
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="is_active" value="{{ $service->is_active ? '0' : '1' }}">
                                    <button type="submit" class="dropdown-item" aria-label="{{ $service->is_active ? 'Deactivate' : 'Activate' }} {{ $service->name }}">{{ $service->is_active ? 'Deactivate' : 'Activate' }}</button>
                                </form></li>
                            </ul>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
        <div class="rv-pagination"><span class="app-note">Showing {{ $services->firstItem() }}–{{ $services->lastItem() }} of {{ $services->total() }} services</span>{{ $services->links('pagination.civic') }}</div>
    @endif
</section>
@endsection
