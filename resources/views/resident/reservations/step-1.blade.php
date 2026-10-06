<h2 id="reservation-step-heading">Which service do you need?</h2>
<p class="text-secondary">Select one of the services currently offered by your barangay.</p>
@if($services->isEmpty())
    <div class="service-empty"><x-app-icon name="services"/><h3>No services available</h3><p>There are no active services at the moment. Please contact the barangay office for assistance.</p></div>
    <a class="btn btn-outline-secondary" href="{{ route('resident.home') }}">Back to dashboard</a>
@else
    <form method="POST" action="{{ route('resident.reservations.service') }}" data-service-submit>
        @csrf
        <fieldset><legend class="visually-hidden">Choose service</legend>
            <div class="reservation-options">
                @foreach($services as $option)
                    <label class="reservation-option" for="service-{{ $option->id }}">
                        <input id="service-{{ $option->id }}" type="radio" name="service_id" value="{{ $option->id }}" required @checked((string) old('service_id', $draft['service_id'] ?? '') === (string) $option->id)>
                        <span><strong>{{ $option->name }}</strong>@if($option->description)<span class="option-description">{{ $option->description }}</span>@endif</span>
                    </label>
                @endforeach
            </div>
        </fieldset>
        <div class="reservation-actions"><a href="{{ route('resident.home') }}" class="btn btn-outline-secondary">Back to dashboard</a><button class="btn btn-primary" type="submit">Continue to requirements</button></div>
    </form>
@endif
