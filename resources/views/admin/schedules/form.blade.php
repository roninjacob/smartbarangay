@extends('layouts.authenticated')
@section('dashboard')
<a href="{{ route('admin.schedules.index') }}" class="service-back-link">&larr; Back to schedules</a>
<div class="row g-4">
    <div class="col-xl-8"><section class="dashboard-panel service-form-panel" aria-labelledby="schedule-form-heading">
        <div class="dashboard-panel-heading"><div><h2 id="schedule-form-heading">{{ $schedule->exists ? 'Appointment slot details' : 'Add an appointment slot' }}</h2><p class="panel-description">Set a date, time range and capacity for Barangay Calayo.</p></div></div>
        <form method="POST" action="{{ $schedule->exists ? route('admin.schedules.update', $schedule) : route('admin.schedules.store') }}" class="service-form" data-service-submit>
            @csrf
            @if($schedule->exists) @method('PUT') @endif
            @if($errors->any())<div class="alert alert-danger" role="alert">Please correct the highlighted fields and try again.</div>@endif
            <div class="mb-4">
                <label for="schedule-date" class="form-label">Appointment Date <span class="service-required">(required)</span></label>
                <input id="schedule-date" name="date" type="date" @class(['form-control', 'is-invalid' => $errors->has('date')]) value="{{ is_string(old('date', $schedule->date?->toDateString())) ? old('date', $schedule->date?->toDateString()) : '' }}" required aria-describedby="schedule-date-help{{ $errors->has('date') ? ' schedule-date-error' : '' }}" @if($errors->has('date')) aria-invalid="true" @endif>
                <div class="form-text" id="schedule-date-help">Choose the date for this appointment slot.</div>
                @error('date')<div class="invalid-feedback" id="schedule-date-error">{{ $message }}</div>@enderror
            </div>
            <div class="row g-3 mb-4">
                <div class="col-sm-6"><label for="schedule-start" class="form-label">Start Time <span class="service-required">(required)</span></label><input id="schedule-start" name="start_time" type="time" @class(['form-control', 'is-invalid' => $errors->has('start_time')]) value="{{ is_string(old('start_time', substr($schedule->start_time ?? '', 0, 5))) ? old('start_time', substr($schedule->start_time ?? '', 0, 5)) : '' }}" required step="60" aria-describedby="schedule-time-help{{ $errors->has('start_time') ? ' schedule-start-error' : '' }}" @if($errors->has('start_time')) aria-invalid="true" @endif>@error('start_time')<div class="invalid-feedback" id="schedule-start-error">{{ $message }}</div>@enderror</div>
                <div class="col-sm-6"><label for="schedule-end" class="form-label">End Time <span class="service-required">(required)</span></label><input id="schedule-end" name="end_time" type="time" @class(['form-control', 'is-invalid' => $errors->has('end_time')]) value="{{ is_string(old('end_time', substr($schedule->end_time ?? '', 0, 5))) ? old('end_time', substr($schedule->end_time ?? '', 0, 5)) : '' }}" required step="60" aria-describedby="schedule-time-help{{ $errors->has('end_time') ? ' schedule-end-error' : '' }}" @if($errors->has('end_time')) aria-invalid="true" @endif>@error('end_time')<div class="invalid-feedback" id="schedule-end-error">{{ $message }}</div>@enderror</div>
                <div class="col-12 mt-2"><div class="form-text" id="schedule-time-help">Use Philippine local time. End time must be later than start time on the same date.</div></div>
            </div>
            <div class="mb-4">
                <label for="schedule-capacity" class="form-label">Slot Capacity <span class="service-required">(required)</span></label>
                <input id="schedule-capacity" name="capacity" type="number" @class(['form-control', 'is-invalid' => $errors->has('capacity')]) value="{{ is_scalar(old('capacity', $schedule->capacity)) ? old('capacity', $schedule->capacity) : '' }}" min="1" max="4294967295" step="1" required aria-describedby="schedule-capacity-help{{ $errors->has('capacity') ? ' schedule-capacity-error' : '' }}" @if($errors->has('capacity')) aria-invalid="true" @endif>
                <div class="form-text" id="schedule-capacity-help">Set the maximum capacity for this slot. Minimum 1.</div>
                @error('capacity')<div class="invalid-feedback" id="schedule-capacity-error">{{ $message }}</div>@enderror
            </div>
            <div class="service-form-actions"><button type="submit" class="btn btn-primary" data-saving-label="Saving…">{{ $schedule->exists ? 'Save Changes' : 'Create Schedule' }}</button><a class="btn btn-outline-secondary" href="{{ route('admin.schedules.index') }}">Cancel</a></div>
        </form>
    </section></div>
    <div class="col-xl-4"><aside class="dashboard-panel service-form-note" aria-labelledby="schedule-note-heading"><span class="service-empty-icon"><x-app-icon name="calendar"/></span><h2 id="schedule-note-heading">Schedule availability</h2><p>{{ $schedule->exists ? 'Activate or deactivate this slot from the schedules list.' : 'New schedules start active. You can deactivate them from the schedules list when needed.' }}</p>@if($schedule->exists)<span @class(['service-status', 'is-active' => $schedule->is_active])>{{ $schedule->is_active ? 'Active' : 'Inactive' }}</span>@endif<p class="mb-0 mt-3">Deactivation preserves existing records. Schedules linked to reservations cannot be deleted.</p></aside></div>
</div>
@endsection
