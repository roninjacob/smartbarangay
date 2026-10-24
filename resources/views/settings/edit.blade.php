@extends('layouts.authenticated')

@section('dashboard')
<div class="user-preferences">
    <p class="text-body-secondary mb-4">Make SmartBarangay comfortable for you. Your appearance preference follows your account when you sign in again.</p>
    <div class="row g-4">
        <div class="col-xl-7">
            <section class="dashboard-panel p-4 h-100" aria-labelledby="appearance-heading">
                <span class="app-eyebrow">YOUR SPACE</span>
                <h2 id="appearance-heading" class="h4">Appearance</h2>
                <p class="text-body-secondary">Choose a light or dark interface. Document previews and printed certificates keep their original appearance.</p>
                @if($errors->any())
                    <div class="alert alert-danger" role="alert"><strong>Your preference was not saved.</strong><ul class="mb-0 mt-2">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
                @endif
                <form method="POST" action="{{ route(auth()->user()->role->value.'.settings.update') }}" data-preferences-form>
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="theme_preference" value="light">
                    <div class="preference-option rounded-4 p-4 my-4">
                        <div class="form-check form-switch d-flex align-items-center gap-3 mb-2">
                            <input class="form-check-input mt-0" type="checkbox" role="switch" id="dark-mode" name="theme_preference" value="dark" @checked(auth()->user()->preferredTheme() === 'dark') aria-describedby="theme-help">
                            <label class="form-check-label fw-semibold" for="dark-mode">Dark mode</label>
                        </div>
                        <p class="small text-body-secondary mb-0" id="theme-help">Preview the appearance here, then save to apply it to your account.</p>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <button type="submit" class="btn btn-primary rounded-pill px-4 py-2">Save appearance</button>
                        <p class="small text-body-secondary mb-0" role="status" aria-live="polite" data-theme-feedback>Saved appearance: {{ ucfirst(auth()->user()->preferredTheme()) }}.</p>
                    </div>
                </form>
            </section>
        </div>
        <div class="col-xl-5">
            <section class="dashboard-panel p-4 h-100" aria-labelledby="about-settings-heading">
                <span class="app-eyebrow">BUILT FOR OUR COMMUNITY</span>
                <h2 id="about-settings-heading" class="h4">About SmartBarangay</h2>
                <p>A web-based barangay document reservation system for Barangay Calayo, Nasugbu, Batangas.</p>
                <dl class="mb-4"><dt>What you can do</dt><dd class="text-body-secondary">Request document services, follow reservation updates, and present your QR ticket for authorized verification.</dd><dt>Account roles</dt><dd class="text-body-secondary">Resident and Admin</dd></dl>
                <a class="btn btn-outline-primary rounded-pill" href="{{ route('home') }}">Visit public website</a>
            </section>
        </div>
        @if(auth()->user()->role === \App\Enums\UserRole::Admin)
            <div class="col-12">
                <section class="dashboard-panel p-4" aria-labelledby="system-controls-heading">
                    <span class="app-eyebrow">ADMIN ONLY</span>
                    <h2 id="system-controls-heading" class="h5">System controls</h2>
                    <p class="text-body-secondary mb-0">Reserved for future approved system controls. No operational switches are available in this phase.</p>
                </section>
            </div>
        @endif
    </div>
</div>
@endsection
