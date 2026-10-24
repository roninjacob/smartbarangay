@unless($reservation->hasAppointmentSnapshot())
    <p class="app-note">Legacy appointment: original date and time were not recorded separately. The linked schedule is shown.</p>
@endunless
