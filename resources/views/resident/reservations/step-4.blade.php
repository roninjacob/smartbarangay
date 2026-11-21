<h2 id="reservation-step-heading">Confirm your reservation</h2>
<p class="rv-step-lead">Check the details, attach your files, then submit. Your request starts as <strong>Pending</strong>; submitting does not mean it is approved.</p>
<div class="rv-review">
    <div class="rv-review-block">
        <div class="rv-review-head"><h3>Service and schedule</h3><a class="panel-link" href="{{ route('resident.reservations.schedule') }}">Change schedule</a></div>
        <dl class="rv-facts">
            <div><dt>Service</dt><dd>{{ $service->name }}</dd></div>
            <div><dt>Service Fee</dt><dd>{{ \App\Support\ServiceFee::format($draft['reviewed_fee'] ?? null) }}</dd></div>
            <div><dt>Date</dt><dd>{{ $schedule->date->format('l, F j, Y') }}</dd></div>
            <div><dt>Time</dt><dd>{{ \Illuminate\Support\Carbon::parse($schedule->start_time)->format('g:i A') }} – {{ \Illuminate\Support\Carbon::parse($schedule->end_time)->format('g:i A') }}</dd></div>
            <div><dt>Resident</dt><dd>{{ $residentInformation['resident_name'] }}</dd></div>
        </dl>
    </div>
    @if($documentTemplate)
        <div class="rv-review-block">@include('resident.reservations.document-review')</div>
    @endif
    <div class="rv-review-block">
        <div class="rv-review-head"><h3>Requirements to prepare</h3><a class="panel-link" href="{{ route('resident.reservations.create') }}">Change service</a></div>
        @include('resident.reservations.requirements-list')
    </div>
</div>
<form method="POST" action="{{ route('resident.reservations.store') }}" enctype="multipart/form-data" class="mt-4" data-service-submit>
    @csrf
    <input type="hidden" name="confirmation_token" value="{{ $draft['confirmation_token'] }}">
    @if($service->serviceRequirements->isNotEmpty())
        <fieldset class="m-0 p-0 border-0"><legend class="rv-subheading mt-0">Attach requirement files</legend>
            <div class="rv-upload-rules" id="upload-help"><span class="rv-badge is-muted">PDF, JPG/JPEG or PNG only</span><span class="rv-badge is-muted">Maximum 5 MB per file</span><span class="rv-badge is-muted">Private to you and authorized Admin reviewers</span></div>
            <p class="app-note">If submission fails, select the files again.</p>
            @error('attachments')<p class="text-danger" role="alert">{{ $message }}</p>@enderror
            @foreach($service->serviceRequirements as $requirement)
                <div class="rv-upload">
                    <label class="form-label" for="requirement-file-{{ $requirement->id }}">{{ $requirement->name }} <span @class(['rv-tag', 'is-required' => $requirement->is_required])>{{ $requirement->is_required ? 'Required' : 'Optional' }}</span></label>
                    <input id="requirement-file-{{ $requirement->id }}" name="attachments[{{ $requirement->id }}]" type="file" data-friendly-upload class="form-control @error('attachments.'.$requirement->id) is-invalid @enderror" accept=".pdf,.jpg,.jpeg,.png" @required($requirement->is_required) aria-describedby="upload-help file-error-{{ $requirement->id }}">
                    @error('attachments.'.$requirement->id)<p id="file-error-{{ $requirement->id }}" class="invalid-feedback d-block" role="alert">{{ $message }}</p>@else<span id="file-error-{{ $requirement->id }}"></span>@enderror
                </div>
            @endforeach
        </fieldset>
    @endif
    <x-action-bar><a href="{{ route('resident.reservations.schedule') }}" class="btn btn-outline-secondary">Change schedule</a><button class="btn btn-primary" type="submit" data-saving-label="Submitting…">Confirm Reservation</button></x-action-bar>
</form>
