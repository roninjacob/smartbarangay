@if($reservation->resident_snapshot)
    <h3 class="h6 mt-4">Resident information at submission</h3>
    <dl class="reservation-summary document-information-summary">
        <div><dt>Name</dt><dd>{{ $reservation->resident_snapshot['resident_name'] }}</dd></div>
        <div><dt>Address</dt><dd>{{ $reservation->resident_snapshot['resident_address'] ?? 'Not provided' }}</dd></div>
        <div><dt>Contact</dt><dd>{{ $reservation->resident_snapshot['resident_contact'] ?? 'Not provided' }}</dd></div>
    </dl>
@endif
@if($reservation->documentAnswers->isNotEmpty())
    <h3 class="h6 mt-4">Submitted document information</h3>
    <p class="app-note">Recorded with this request. Later profile or document configuration changes do not change these answers.</p>
    <dl class="reservation-summary document-information-summary">
        @foreach($reservation->documentAnswers as $answer)
            <div><dt>{{ $answer->field_snapshot['label'] }}</dt><dd>{{ $answer->answer ?? 'Not provided' }}</dd></div>
        @endforeach
    </dl>
@endif
