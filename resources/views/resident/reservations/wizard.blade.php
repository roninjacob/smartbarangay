@extends('layouts.authenticated')
@section('dashboard')
<div class="rv-wizard reservation-wizard">
    <nav class="rv-wizard-steps" aria-label="Reservation progress">
        <ol>
            @foreach(['Choose service', 'Document information', 'Choose schedule', 'Confirm request'] as $label)
                <li class="{{ $loop->iteration === $step ? 'is-current' : ($loop->iteration < $step ? 'is-complete' : '') }}" @if($loop->iteration === $step) aria-current="step" @endif>
                    <span class="rv-wizard-num" aria-hidden="true">{{ $loop->iteration < $step ? '✓' : $loop->iteration }}</span>
                    <span class="rv-wizard-text">{{ $label }}@if($loop->iteration < $step)<span class="visually-hidden"> — completed</span>@endif</span>
                </li>
            @endforeach
        </ol>
        <div class="rv-wizard-progress" aria-hidden="true"><span style="--rv-progress: {{ $step / 4 }}"></span></div>
    </nav>
    @if($errors->any())
        <div class="alert alert-danger" role="alert" tabindex="-1" data-validation-summary><strong>Please check your request.</strong><ul class="mb-0 mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    <section class="card dashboard-panel rv-step-card" aria-labelledby="reservation-step-heading">
        <span class="visually-hidden">Step {{ $step }} of 4</span>
        @include('resident.reservations.step-'.$step)
    </section>
</div>
@endsection
