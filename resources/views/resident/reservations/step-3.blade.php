<h2 id="reservation-step-heading">Choose your schedule</h2>
<p class="text-secondary">Select a date and time for <strong>{{ $service->name }}</strong>.</p>
@if($schedules->isEmpty())
    <div class="service-empty"><x-app-icon name="calendar"/><h3>No schedules available</h3><p>There are no active schedules at the moment. Please contact the barangay office or check again later.</p></div>
    <a href="{{ route('resident.reservations.requirements') }}" class="btn btn-outline-secondary">Back to requirements</a>
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
        <div class="reservation-actions"><a href="{{ route('resident.reservations.requirements') }}" class="btn btn-outline-secondary">Back to requirements</a><button class="btn btn-primary" type="submit">Review reservation</button></div>
    </form>
@endif
