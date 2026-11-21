<h2 id="reservation-step-heading">{{ $documentTemplate ? 'Document information and requirements' : 'Review what to prepare' }}</h2>
<p class="rv-step-lead">Requirements for <strong>{{ $service->name }}</strong>. Read the list before choosing your schedule.</p>
<div class="rv-review-block mb-3">
    <div class="rv-review-head"><h3>What to prepare</h3><span class="rv-badge">Service Fee: {{ \App\Support\ServiceFee::format($service->fee_amount) }}</span></div>
    @include('resident.reservations.requirements-list')
</div>
<div class="rv-upload-rules" aria-label="Upload rules"><span class="rv-badge is-muted">Upload files on the confirmation step</span><span class="rv-badge is-muted">PDF, JPG/JPEG or PNG</span><span class="rv-badge is-muted">Up to 5 MB per file</span></div>
<form method="POST" action="{{ route('resident.reservations.requirements.review') }}" data-service-submit data-unsaved-document-form>
    @csrf
    <input type="hidden" name="requirement_signature" value="{{ $requirementSignature }}">
    @include('resident.reservations.document-form')
    <p class="form-text">Continue to save your answers. Unsaved changes are lost if you open Profile / Account Settings first.</p>
    <x-action-bar><a href="{{ route('resident.reservations.create') }}" class="btn btn-outline-secondary">Change service</a><button class="btn btn-primary" type="submit" @disabled(!empty($missingProfileFields))>Continue to schedules <x-app-icon name="arrow"/></button></x-action-bar>
</form>
