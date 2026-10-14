@extends('layouts.authenticated')
@section('dashboard')
<a class="service-back-link" href="{{ $backUrl }}">&larr; Back to reservation details</a>
<section class="card dashboard-panel reservation-panel">
    <h2>{{ $reservation->service->name }}</h2>
    <p class="app-note">The saved personalized certificate. Its recorded information stays unchanged after profile or template edits.</p>
    @include('admin.services.document-preview')
</section>
@endsection
