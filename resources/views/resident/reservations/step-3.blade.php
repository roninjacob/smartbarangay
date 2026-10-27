<h2 id="reservation-step-heading">Choose your schedule</h2>
<p class="text-secondary">Select a date and time for <strong>{{ $service->name }}</strong>.</p>
<form method="GET" action="{{ route('resident.reservations.schedule') }}" class="request-filters mb-4">
    <label for="appointment-filter" class="form-label">Find an appointment date</label>
    <div class="d-flex flex-wrap gap-3"><input type="date" id="appointment-filter" name="date" class="form-control w-auto" value="{{ $scheduleFilters['date'] ?? '' }}"><button type="submit" class="btn btn-outline-primary">Find slots</button><a class="btn btn-outline-secondary" href="{{ route('resident.reservations.schedule') }}">All dates</a></div>
</form>
@if($schedules->isEmpty())
    <div class="service-empty"><x-app-icon name="calendar"/><h3>{{ $schedules->total() > 0 ? 'No slots on this page' : (empty($scheduleFilters['date']) ? 'No schedules available' : 'No slots for this date') }}</h3><p>Try All dates to see other available appointments, or contact the office if no future slots are available.</p></div>
    @if($schedules->total() > 0)<a class="btn btn-primary" href="{{ route('resident.reservations.schedule', array_filter(['date' => $scheduleFilters['date'] ?? null])) }}">Back to first page</a>@endif
    <a href="{{ route('resident.reservations.requirements') }}" class="btn btn-outline-secondary">Back to information</a>
@else
    <form method="POST" action="{{ route('resident.reservations.schedule.select') }}" data-service-submit>
        @csrf
        <fieldset><legend class="visually-hidden">Choose schedule</legend>
            <div class="reservation-options">
                @foreach($schedules as $option)
                    <label class="reservation-option" for="schedule-{{ $option->id }}">
                        <input id="schedule-{{ $option->id }}" type="radio" name="schedule_id" value="{{ $option->id }}" required @disabled($option->remainingSlots() === 0) @checked($option->remainingSlots() > 0 && (string) old('schedule_id', $draft['schedule_id'] ?? '') === (string) $option->id)>
                        <span><strong>{{ $option->date->format('l, F j, Y') }}</strong><span class="option-description">{{ \Illuminate\Support\Carbon::parse($option->start_time)->format('g:i A') }} – {{ \Illuminate\Support\Carbon::parse($option->end_time)->format('g:i A') }}</span><span class="requirement-label d-inline-block mt-2">{{ $option->remainingSlots() === 0 ? 'Full' : $option->remainingSlots().' slots remaining' }}</span></span>
                    </label>
                @endforeach
            </div>
        </fieldset>
        <div class="reservation-actions"><a href="{{ route('resident.reservations.requirements') }}" class="btn btn-outline-secondary">Back to information</a><button class="btn btn-primary" type="submit">Review reservation</button></div>
    </form>
@endif

@unless($schedules->isEmpty())
    {{ $schedules->links('pagination.civic', ['showSummary' => true]) }}
@endunless
