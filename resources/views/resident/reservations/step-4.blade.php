<h2 id="reservation-step-heading">Confirm your reservation</h2>
<p class="text-secondary">Check the details below before submitting your request.</p>
<dl class="reservation-summary">
    <div><dt>Service</dt><dd>{{ $service->name }}</dd></div>
    <div><dt>Date</dt><dd>{{ $schedule->date->format('l, F j, Y') }}</dd></div>
    <div><dt>Time</dt><dd>{{ \Illuminate\Support\Carbon::parse($schedule->start_time)->format('g:i A') }} – {{ \Illuminate\Support\Carbon::parse($schedule->end_time)->format('g:i A') }}</dd></div>
    <div><dt>Resident</dt><dd>{{ auth()->user()->name }}</dd></div>
</dl>
<h3 class="h6 mt-4">Requirements to prepare</h3>
@include('resident.reservations.requirements-list')
<p class="reservation-notice">Your request will start as <strong>Pending</strong>. Submission does not mean the request has been approved.</p>
<form method="POST" action="{{ route('resident.reservations.store') }}" enctype="multipart/form-data" data-service-submit>
    @csrf
    <input type="hidden" name="confirmation_token" value="{{ $draft['confirmation_token'] }}">
    @if($service->serviceRequirements->isNotEmpty())
        <fieldset class="mt-4"><legend class="h5">Attach requirement files</legend>
            <p class="app-note" id="upload-help">PDF, JPG/JPEG or PNG only. Maximum 5 MB per file. Files are private to you and authorized Admin reviewers. If submission fails, select the files again.</p>
            @error('attachments')<p class="text-danger" role="alert">{{ $message }}</p>@enderror
            @foreach($service->serviceRequirements as $requirement)
                <div class="mb-4">
                    <label class="form-label" for="requirement-file-{{ $requirement->id }}">{{ $requirement->name }} <span class="requirement-label">{{ $requirement->is_required ? 'Required' : 'Optional' }}</span></label>
                    <input id="requirement-file-{{ $requirement->id }}" name="attachments[{{ $requirement->id }}]" type="file" class="form-control @error('attachments.'.$requirement->id) is-invalid @enderror" accept=".pdf,.jpg,.jpeg,.png" @required($requirement->is_required) aria-describedby="upload-help file-error-{{ $requirement->id }}">
                    @error('attachments.'.$requirement->id)<p id="file-error-{{ $requirement->id }}" class="invalid-feedback" role="alert">{{ $message }}</p>@else<span id="file-error-{{ $requirement->id }}"></span>@enderror
                </div>
            @endforeach
        </fieldset>
    @endif
    <div class="reservation-actions"><a href="{{ route('resident.reservations.schedule') }}" class="btn btn-outline-secondary">Change schedule</a><button class="btn btn-primary" type="submit" data-saving-label="Submitting…">Confirm Reservation</button></div>
</form>
