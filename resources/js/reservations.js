// Mirror the conditional server rule for clear browser feedback.
document.querySelectorAll('[data-reservation-status]').forEach((select) => {
    const notes = select.form.querySelector('[data-reservation-notes]');
    if (!notes) return;
    const updateRequired = () => { notes.required = select.value === 'rejected'; };
    select.addEventListener('change', updateRequired);
    updateRequired();
});
