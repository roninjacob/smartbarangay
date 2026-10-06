@props(['user', 'roleLabel'])
<div class="modal fade" id="account-summary" tabindex="-1" aria-labelledby="account-summary-heading" aria-describedby="account-summary-note" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <section class="modal-content app-account-modal">
            <div class="modal-header">
                <div><span class="app-eyebrow">YOUR SMARTBARANGAY ACCOUNT</span><h2 id="account-summary-heading" class="modal-title fs-5">Profile overview</h2></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close profile overview"></button>
            </div>
            <div class="modal-body">
                <dl class="account-details">
                    <dt>Full name</dt><dd>{{ $user->name }}</dd>
                    <dt>Email address</dt><dd>{{ $user->email }}</dd>
                    <dt>Account type</dt><dd>{{ $roleLabel }}</dd>
                    <dt>Member since</dt><dd>{{ $user->created_at->timezone('Asia/Manila')->format('F j, Y') }}</dd>
                </dl>
                <p id="account-summary-note" class="app-note mb-0"><a href="{{ route($user->role->value.'.profile.edit') }}">Manage your profile and optional picture</a> in Profile / Account Settings.</p>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Close</button></div>
        </section>
    </div>
</div>
