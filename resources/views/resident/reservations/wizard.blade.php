@extends('layouts.authenticated')
@section('dashboard')
<div class="reservation-wizard">
    <p class="reservation-intro">Request a document from Barangay Calayo. Choose your service, review what to prepare, and select a schedule.</p>
    <ol class="reservation-steps" aria-label="Reservation progress">
        @foreach(['Choose service', 'Review requirements', 'Choose schedule', 'Confirm request'] as $label)
            <li class="{{ $loop->iteration === $step ? 'is-current' : ($loop->iteration < $step ? 'is-complete' : '') }}" @if($loop->iteration === $step) aria-current="step" @endif>
                <span class="step-number" aria-hidden="true">{{ $loop->iteration }}</span><span>{{ $label }}@if($loop->iteration < $step)<span class="visually-hidden"> — completed</span>@endif</span>
            </li>
        @endforeach
    </ol>
    @if($errors->any())
        <div class="alert alert-danger" role="alert"><strong>Please check your request.</strong><ul class="mb-0 mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    <section class="card dashboard-panel reservation-panel" aria-labelledby="reservation-step-heading">
        <span class="reservation-step-label">STEP {{ $step }} OF 4</span>
        @include('resident.reservations.step-'.$step)
    </section>
    <p class="reservation-help">Need assistance? Contact the Barangay Calayo office in Nasugbu, Batangas.</p>
</div>
@endsection
