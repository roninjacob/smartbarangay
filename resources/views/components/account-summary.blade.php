@props(['user', 'roleLabel'])
@php($picturePath = $user->ownedProfilePicturePath())
<div class="modal fade" id="account-summary" tabindex="-1" aria-labelledby="account-summary-heading" aria-describedby="account-summary-note" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <section class="modal-content app-account-modal">
            <div class="modal-header">
                <div><span class="app-eyebrow">YOUR SMARTBARANGAY ACCOUNT</span><h2 id="account-summary-heading" class="modal-title fs-5">Profile overview</h2></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close profile overview"></button>
            </div>
            <div class="modal-body">
                <div class="account-person">
                    <span class="account-person-avatar" aria-hidden="true">@if($picturePath && \Illuminate\Support\Facades\Storage::disk('local')->exists($picturePath))<img src="{{ route($user->role->value.'.profile.picture') }}" alt="" width="56" height="56">@else{{ mb_substr($user->name, 0, 1) }}@endif</span>
                    <div><strong>{{ $user->name }}</strong><span>{{ $roleLabel }} account</span></div>
                </div>
                <dl class="account-details">
                    <div><dt>Email address</dt><dd>{{ $user->email }}</dd></div>
                    <div><dt>Account type</dt><dd>{{ $roleLabel }}</dd></div>
                    <div><dt>Member since</dt><dd>{{ $user->created_at->timezone('Asia/Manila')->format('F j, Y') }}</dd></div>
                </dl>
                <p id="account-summary-note" class="app-note mb-0">Keep your information up to date in Profile / Account Settings.</p>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button><a class="btn btn-primary" href="{{ route($user->role->value.'.profile.edit') }}">Manage your profile and optional picture <x-app-icon name="arrow"/></a></div>
        </section>
    </div>
</div>
