@if($reservation->resident_snapshot)
    <h3 class="rv-subheading">Resident information at submission</h3>
    <dl class="rv-facts">
        <div><dt>Name</dt><dd>{{ $reservation->resident_snapshot['resident_name'] }}</dd></div>
        <div><dt>Address</dt><dd>{{ $reservation->resident_snapshot['resident_address'] ?? 'Not provided' }}</dd></div>
        <div><dt>Contact</dt><dd>{{ $reservation->resident_snapshot['resident_contact'] ?? 'Not provided' }}</dd></div>
    </dl>
@endif
@if($reservation->documentAnswers->isNotEmpty())
    <h3 class="rv-subheading">Submitted document information</h3>
    <p class="app-note">Recorded with this request. Later profile or document configuration changes do not change these answers.</p>
    @if(\App\Services\DocumentSystemFields::ISSUE_POLICY === ($reservation->resident_snapshot['_document_issue_policy'] ?? null))<p class="app-note">Age is recorded at submission. Document date values below are submission previews; the saved certificate uses its finalization date in Philippine time.</p>@endif
    <dl class="rv-facts">
        @foreach($reservation->documentAnswers as $answer)
            <div><dt>{{ $answer->field_snapshot['label'] }}</dt><dd>{{ $answer->answer ?? 'Not provided' }}</dd></div>
        @endforeach
    </dl>
@endif
