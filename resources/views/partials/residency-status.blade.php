@php
    $status = $resident->residency_status;
    $review = $resident->residency_revision > 0 ? $resident->residencyReviews()->latest('id')->first() : null;
    $approved = $status === \App\Enums\ResidencyStatus::Approved;
@endphp
<section @class(['residency-banner', 'is-approved' => $approved, 'is-pending' => ! $approved]) aria-labelledby="residency-heading">
    <span class="residency-banner-icon" aria-hidden="true"><x-app-icon :name="$approved ? 'check' : 'users'"/></span>
    <div class="residency-banner-body">
        <h2 id="residency-heading">{{ $status->label() }}</h2>
        @if($approved)
            <p>You can submit new barangay document requests.</p>
        @else
            <p>An Admin must approve your residency in Barangay Calayo before you can submit a new request. You can update your profile and track your existing reservations.</p>
        @endif
        @if($review?->to_status === $status && $review?->resident_message)<p class="residency-banner-message"><strong>Message from the barangay office</strong><br>{{ $review->resident_message }}</p>@endif
        @if($resident->residency_reviewed_at)<p class="residency-banner-meta">Last reviewed {{ $resident->residency_reviewed_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}</p>@endif
    </div>
</section>
