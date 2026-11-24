@if($reservation->status->canBeCancelledByResident())
<section class="card dashboard-panel rv-section" aria-labelledby="cancellation-heading">
    <div class="dashboard-panel-heading"><h2 id="cancellation-heading"><span class="panel-icon is-red" aria-hidden="true"><x-app-icon name="status"/></span>Cancel this reservation</h2></div>
    <div class="rv-section-body">
    <p class="app-note">You can cancel while your request is Pending. Cancellation stops processing and keeps your request history.</p>
    <form method="POST" action="{{ route('resident.reservations.cancel', $reservation) }}" data-reservation-cancel>
        @csrf
        <label for="cancellation-reason" class="form-label">Cancellation reason <span class="rv-tag is-required">Required</span></label>
        <textarea id="cancellation-reason" name="reason" @class(['form-control', 'is-invalid' => $errors->has('reason')]) rows="3" maxlength="2000" required aria-describedby="cancellation-help{{ $errors->has('reason') ? ' cancellation-error' : '' }}">{{ is_string(old('reason')) ? old('reason') : '' }}</textarea>
        <div id="cancellation-help" class="form-text">Explain why you no longer need this reservation. Maximum 2,000 characters.</div>
        @error('reason')<div class="invalid-feedback" id="cancellation-error">{{ $message }}</div>@enderror
        <button class="btn btn-outline-danger w-100 mt-3" type="submit">Cancel reservation</button>
    </form>
    </div>
</section>
@push('modals')
<div class="modal fade reservation-confirmation" id="reservation-cancel-confirmation" tabindex="-1" aria-labelledby="cancel-confirmation-heading" aria-describedby="cancel-confirmation-description" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><section class="modal-content">
        <div class="modal-header"><h2 id="cancel-confirmation-heading" class="modal-title fs-5">Cancel this request?</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close cancellation confirmation"></button></div>
        <div class="modal-body"><p id="cancel-confirmation-description" class="mb-0">This Pending request will stop processing. Your reservation and cancellation reason will remain in its history.</p></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep reservation</button><button type="button" class="btn btn-danger" data-cancellation-confirm>Confirm cancellation</button></div>
    </section></div>
</div>
@endpush
@elseif($errors->has('reason'))
    <div class="alert alert-danger mt-3" role="alert">{{ $errors->first('reason') }}</div>
@endif
