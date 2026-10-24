@extends('layouts.authenticated')
@section('dashboard')
<a class="service-back-link mb-3" href="{{ route('admin.services.index') }}">&larr; Back to service catalog</a>
<section class="dashboard-panel service-form-panel p-3 p-md-4" aria-labelledby="certificates-heading">
    <h2 id="certificates-heading" class="text-break">{{ $service->name }}</h2>
    <p class="text-break">{{ $service->description ?: 'Official certificate for Barangay Calayo.' }}</p>
    <p class="app-note">Print blank copies here, even without approved requests. Printing does not change request statuses.</p>
    @if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
    @if($service->documentTemplate)<p class="text-break"><strong>Official Certificate PDF:</strong> {{ $service->documentTemplate->original_filename }}</p>@endif
    @include('admin.services.document-preview', ['previewHeading' => 'Preview Blank Template'])
    <div class="d-flex flex-wrap gap-2 mb-4">
        @if($preview['kind'] === 'pdf')
            <a class="btn btn-outline-primary" href="{{ route('admin.services.template.preview', $service) }}" target="_blank" rel="noopener">Preview Blank Template</a>
            <a class="btn btn-primary" href="{{ route('admin.services.template.print', $service) }}">Print Blank Template</a>
            <a class="btn btn-outline-secondary" href="{{ route('admin.services.template.download', $service) }}">Download Blank PDF</a>
        @endif
        <a class="btn btn-outline-secondary" href="{{ route('admin.services.edit', $service) }}">Edit Service</a>
    </div>
    <h3 class="h5">Approved Requests</h3>
    @forelse($reservations as $reservation)
        <div class="border rounded p-3 mb-3 text-break">
            <strong>{{ $reservation->user->name }}</strong>
            <p class="app-note">Request #{{ $reservation->id }} &middot; {{ $reservation->appointment->date->format('M j, Y') }}</p>
            @if($certificateStates[$reservation->id]['available'])
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.reservations.generated.show', $reservation) }}">Preview Generated Document<span class="visually-hidden"> for Request #{{ $reservation->id }}</span></a>
                    <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.reservations.generated.download', $reservation) }}">Download<span class="visually-hidden"> for Request #{{ $reservation->id }}</span></a>
                    @if($certificateStates[$reservation->id]['can_print'])<a class="btn btn-sm btn-primary" href="{{ route('admin.reservations.document.print', $reservation) }}">Print Certificate<span class="visually-hidden"> for Request #{{ $reservation->id }}</span></a>@endif
                </div>
            @else
                <p class="app-note">{{ $certificateStates[$reservation->id]['message'] }}</p>
            @endif
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.reservations.show', $reservation) }}">View Request</a>
        </div>
    @empty
        <p class="app-note">No approved requests for this service.</p>
    @endforelse
    {{ $reservations->links('pagination::bootstrap-5') }}
</section>
@endsection
