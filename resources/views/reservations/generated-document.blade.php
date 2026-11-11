@extends('layouts.authenticated')
@section('dashboard')
<a class="service-back-link" href="{{ $backUrl }}">&larr; Back to reservation details</a>
<section class="card dashboard-panel reservation-panel">
    <h2>{{ $reservation->service->name }}</h2>
    <p class="app-note">The saved personalized certificate. Its recorded information stays unchanged after profile or template edits.</p>
    @if(\App\Services\DocumentSystemFields::ISSUE_POLICY === ($reservation->resident_snapshot['_document_issue_policy'] ?? null))<p class="app-note">Document issue date · {{ $reservation->document->generated_at->timezone('Asia/Manila')->format('F j, Y') }}</p>@endif
    @include('admin.services.document-preview')
</section>
@endsection
