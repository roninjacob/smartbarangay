<h2 id="reservation-step-heading">Which service do you need?</h2>
<p class="rv-step-lead">Select one of the services offered by Barangay Calayo.</p>
@if($services->isEmpty())
    <div class="service-empty"><span class="service-empty-icon"><x-app-icon name="services"/></span><h3>No services available</h3><p>There are no active services at the moment. Please contact the barangay office for assistance.</p></div>
    <x-action-bar><a class="btn btn-outline-secondary" href="{{ route('resident.home') }}">Back to dashboard</a></x-action-bar>
@else
    <form method="POST" action="{{ route('resident.reservations.service') }}" data-service-submit>
        @csrf
        <fieldset class="rv-options"><legend class="visually-hidden">Choose service</legend>
            @foreach($services as $option)
                @php
                    $fee = $option->fee_amount;
                    $feeLabel = \App\Support\ServiceFee::format($fee, 'Not yet configured — contact the barangay office');
                @endphp
                <x-option-card name="service_id" :value="$option->id" :id="'service-'.$option->id" :required="true" :disabled="$fee === null"
                    :checked="$fee !== null && (string) old('service_id', $draft['service_id'] ?? '') === (string) $option->id">
                    <span class="rv-option-title">{{ $option->name }}</span>
                    @if($option->description)<span class="rv-option-text">{{ $option->description }}</span>@endif
                    <span class="rv-option-meta">
                        <span @class(['rv-badge', 'is-green' => $fee !== null && (float) $fee === 0.0, 'is-muted' => $fee === null])>Service Fee: {{ $feeLabel }}</span>
                        @if($option->service_requirements_count > 0)<span class="rv-badge is-gold">{{ $option->service_requirements_count }} {{ \Illuminate\Support\Str::plural('requirement', $option->service_requirements_count) }}</span>@endif
                    </span>
                </x-option-card>
            @endforeach
        </fieldset>
        <x-action-bar><a href="{{ route('resident.home') }}" class="btn btn-outline-secondary">Back to dashboard</a><button class="btn btn-primary" type="submit">Continue to information <x-app-icon name="arrow"/></button></x-action-bar>
    </form>
@endif
