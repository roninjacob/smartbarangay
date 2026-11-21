<h2 id="reservation-step-heading">Choose your schedule</h2>
<p class="rv-step-lead">Select a date and time for <strong>{{ $service->name }}</strong>.</p>
<form method="GET" action="{{ route('resident.reservations.schedule') }}" class="request-filters rv-filter mb-4" style="--rv-filter-selects: 0">
    <div><label for="appointment-filter" class="form-label">Find an appointment date</label><input type="date" id="appointment-filter" name="date" class="form-control" value="{{ $scheduleFilters['date'] ?? '' }}"></div>
    <div class="rv-filter-actions"><button type="submit" class="btn btn-primary">Find slots</button><a class="btn btn-outline-secondary" href="{{ route('resident.reservations.schedule') }}">All dates</a></div>
</form>
@if($schedules->isEmpty())
    <div class="service-empty"><span class="service-empty-icon"><x-app-icon name="calendar"/></span><h3>{{ $schedules->total() > 0 ? 'No slots on this page' : (empty($scheduleFilters['date']) ? 'No schedules available' : 'No slots for this date') }}</h3><p>Try All dates to see other appointments, or contact the office if no future slots are available.</p></div>
    <x-action-bar>
        <a href="{{ route('resident.reservations.requirements') }}" class="btn btn-outline-secondary">Back to information</a>
        @if($schedules->total() > 0)<a class="btn btn-primary" href="{{ route('resident.reservations.schedule', array_filter(['date' => $scheduleFilters['date'] ?? null])) }}">Back to first page</a>@endif
    </x-action-bar>
@else
    <form method="POST" action="{{ route('resident.reservations.schedule.select') }}" data-service-submit>
        @csrf
        <fieldset class="m-0 p-0 border-0"><legend class="visually-hidden">Choose schedule</legend>
            @foreach($schedules->groupBy(fn ($slot) => $slot->date->toDateString()) as $day => $slots)
                <div class="rv-slot-group">
                    <h3><x-app-icon name="calendar"/>{{ $slots->first()->date->format('l, F j, Y') }}</h3>
                    <div class="rv-options">
                        @foreach($slots as $option)
                            @php($remaining = $option->remainingSlots())
                            <x-option-card name="schedule_id" :value="$option->id" :id="'schedule-'.$option->id" :required="true" :disabled="$remaining === 0"
                                :checked="$remaining > 0 && (string) old('schedule_id', $draft['schedule_id'] ?? '') === (string) $option->id">
                                <span class="rv-option-title">{{ \Illuminate\Support\Carbon::parse($option->start_time)->format('g:i A') }} – {{ \Illuminate\Support\Carbon::parse($option->end_time)->format('g:i A') }}</span>
                                <span class="rv-option-meta"><span @class(['rv-badge', 'is-green' => $remaining > 3, 'is-gold' => $remaining > 0 && $remaining <= 3, 'is-muted' => $remaining === 0])>{{ $remaining === 0 ? 'Full' : $remaining.' slots remaining' }}</span></span>
                            </x-option-card>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </fieldset>
        {{ $schedules->links('pagination.civic', ['showSummary' => true]) }}
        <x-action-bar><a href="{{ route('resident.reservations.requirements') }}" class="btn btn-outline-secondary">Back to information</a><button class="btn btn-primary" type="submit">Review reservation <x-app-icon name="arrow"/></button></x-action-bar>
    </form>
@endif
