@php
    $status = $resident->residency_status;
    $review = $resident->residency_revision > 0 ? $resident->residencyReviews()->latest('id')->first() : null;
@endphp
<section class="alert {{ $status === \App\Enums\ResidencyStatus::Approved ? 'alert-success' : 'alert-info' }} mb-4" aria-labelledby="residency-heading">
    <h2 id="residency-heading" class="h5">{{ $status->label() }}</h2>
    @if($status === \App\Enums\ResidencyStatus::Approved)
        <p class="mb-0">You can submit new barangay document requests.</p>
    @else
        <p class="mb-0">An Admin must approve your residency in Barangay Calayo before you can submit a new request. You can update your profile and track your existing reservations.</p>
    @endif
    @if($review?->to_status === $status && $review?->resident_message)<p class="mb-0 mt-2"><strong>Message from the barangay office</strong><br>{{ $review->resident_message }}</p>@endif
    @if($resident->residency_reviewed_at)<p class="small mb-0 mt-2">Last reviewed {{ $resident->residency_reviewed_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}</p>@endif
</section>
